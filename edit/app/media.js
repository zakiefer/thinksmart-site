// Photos, videos and contracts.
//   Photos: picked from the phone's library (iPhone hands over a JPEG), shrunk to a JPEG here
//   (location and camera details go with it), an optional "match the site's look" darkening
//   where words sit on the photo, "Tap the important part", the "What's in this photo?" box,
//   and "Also on: …" for spots using the same picture.
//   Videos: Use my photo instead / Put the video back.
//   Contracts: the iPhone help card, PDF.js reads the new PDF (other state? signed or filled
//   in?), shows page 1 and asks if it's the blank contract, then "Did the e-sign version
//   change too?"; earlier versions are listed.
import { S, h, icon, clear, getAt, setAt, keyOf, parseKey, schemaPath, field, changed, plain, go, back, render, api, toast, sheet, clone, visibleStates } from './core.js';
import { fieldBox } from './fields.js';
import { itemTitle } from './lists.js';
import { screenFor } from './screens.js';

// where words sit on top of the photo (darkening is on by default there)
const OVER_TEXT = /^(home\.(top|fight|final)\.video\.photo|claim\.top\.video\.photo|services\.top\.video\.photo|about\.top\.photo|home\.closed\.photo|services\.coverage\.tiles\[\]\.photo)$/;
// how each spot crops the photo: [phone, computer] width / height
function aspects(sp) {
  if (/video\.photo$|^about\.top\.photo$|^home\.closed\.photo$/.test(sp)) return [0.55, 1.8];
  if (/^about\.adjuster\.photo$|^home\.lowball\.photo$/.test(sp)) return [0.8, 0.8];
  if (/^site\.sharePicture$/.test(sp)) return [1.91, 1.91];
  return [1.6, 1.6];
}

// ------------------------------------------------------------------ every photo

/** Every place a schema path occurs in the content: [path, value]. */
export function instances(content, sp) {
  const segs = sp.replace(/\[\]/g, '[].').split('.').filter(Boolean);
  const out = [];
  const walk = (node, i, path) => {
    if (i === segs.length) { out.push([path, node]); return; }
    const seg = segs[i];
    if (seg.endsWith('[]')) {
      const list = node && node[seg.slice(0, -2)];
      if (!Array.isArray(list)) return;
      list.forEach((it) => { if (it && it._id) walk(it, i + 1, path.concat([seg.slice(0, -2), { id: it._id }])); });
      return;
    }
    if (!node || typeof node !== 'object' || !(seg in node)) { if (i === segs.length - 1) out.push([path.concat([seg]), undefined]); return; }
    walk(node[seg], i + 1, path.concat([seg]));
  };
  walk(content, 0, []);
  return out;
}

function photoSpots() {
  const sps = Object.keys(S.schema.fields).filter((sp) => {
    const f = field(sp);
    return (f.type === 'photo' && !f.editorOnly && !/\.(poster)$/.test(sp)) || f.type === 'video';
  }).sort((a, b) => (field(a).order || 0) - (field(b).order || 0));
  const out = [];
  for (const sp of sps) for (const [path, v] of instances(S.draft, sp)) {
    if (field(sp).type === 'photo' && sp.startsWith('services.coverage.tiles') && getAt(S.draft, path.slice(0, -1).concat(['video']))) continue;
    if (field(sp).type === 'video' && v === undefined) continue;
    if (field(sp).type === 'video' && sp.startsWith('services.coverage.tiles') && !v && !getAt(S.draft, path.slice(0, -1).concat(['videoWas']))) continue;
    out.push({ path, sp, v });
  }
  return out;
}

function thumbOf(spot) {
  if (field(spot.sp).type === 'video') {
    const parent = getAt(S.draft, spot.path.slice(0, -1)) || {};
    return parent.photo && !spot.sp.startsWith('services.coverage') ? parent.photo : parent.video ? parent.poster : parent.photo;
  }
  return spot.v;
}

/** The spots that belong to one photo or video (not its neighbours in the same part of the page). */
function spotKeys(spot) {
  const parent = spot.path.slice(0, -1);
  const leaf = spot.path[spot.path.length - 1];
  const keys = [spot.path, parent.concat([leaf + 'Focus']), parent.concat([leaf + 'Alt'])];
  if (field(spot.sp).type === 'video') for (const k of ['photo', 'photoFocus', 'poster', 'video', 'videoWas']) keys.push(parent.concat([k]));
  return keys;
}

/** The list item a spot sits in, and that list's schema path (or nulls). */
function itemOf(path) {
  const i = path.map((x) => typeof x).lastIndexOf('object');
  if (i < 0) return [null, ''];
  return [getAt(S.draft, path.slice(0, i + 1)), schemaPath(path.slice(0, i))];
}

const PAGE_OF = [[/^home\./, 'Home'], [/^about\./, 'About'], [/^services\./, 'Services'], [/^contact\./, 'Contact'], [/^claim\./, 'File a Claim'], [/^site\./, 'Sharing']];

export function photosScreen() {
  const groups = {};
  for (const s of photoSpots()) {
    const page = (PAGE_OF.find(([re]) => re.test(s.sp)) || [0, 'Other'])[1];
    (groups[page] = groups[page] || []).push(s);
  }
  const body = h('div.stack', h('p.help', { text: 'Tap a photo to change it. Videos can be swapped for one of your photos.' }));
  for (const [page, spots] of Object.entries(groups)) {
    body.append(h('h3', { text: page }), h('div.photo-grid', spots.map((s) => {
      const f = field(s.sp);
      const t = thumbOf(s);
      let label = f.label.replace(/: (photo|background video: video|tile: video|video)$/, '').replace(/: tile photo$/, '');
      const [item, listSp] = itemOf(s.path);
      if (item) label += ': ' + itemTitle(item, listSp);
      const changedHere = spotKeys(s).some((p) => JSON.stringify(getAt(S.live.content, p) ?? null) !== JSON.stringify(getAt(S.draft, p) ?? null));
      return h('a.photo-cell', { href: '#/photo/' + encodeURIComponent(keyOf(s.path)) },
        t ? h('img', { src: '/' + t, alt: '', loading: 'lazy' }) : h('span.noimg', { text: 'No photo' }),
        f.type === 'video' ? h('span.badge-video', icon('video'), 'Video') : null,
        changedHere ? h('span.pill.changed', { text: 'Changed' }) : null,
        h('span.photo-label', { text: label }));
    })));
  }
  return { title: 'Photos', body };
}

// ------------------------------------------------------------------ the photo pipeline

/** Shrink to a JPEG (as the Claim page does for visitors' photos), optionally darkened. */
export function processPhoto(file, opt = {}) {
  return new Promise((resolve, reject) => {
    if (file.type && !/^image\/(jpeg|png|webp|heic|heif)$/.test(file.type)) { reject(new Error('That file isn\'t a photo. Pick a photo from your library.')); return; }
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      try {
        const max = opt.max || 2000;
        const scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight, 1));
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(img.naturalWidth * scale));
        c.height = Math.max(1, Math.round(img.naturalHeight * scale));
        const ctx = c.getContext('2d');
        ctx.drawImage(img, 0, 0, c.width, c.height);
        if (opt.darken) { ctx.fillStyle = 'rgba(11,14,19,0.3)'; ctx.fillRect(0, 0, c.width, c.height); }
        // how bright it is where words usually sit (the lower part)
        const sw = 48;
        const sh = 48;
        const s = document.createElement('canvas');
        s.width = sw; s.height = sh;
        const sctx = s.getContext('2d');
        sctx.drawImage(c, 0, Math.round(c.height * 0.4), c.width, Math.round(c.height * 0.6), 0, 0, sw, sh);
        const d = sctx.getImageData(0, 0, sw, sh).data;
        let lum = 0;
        for (let i = 0; i < d.length; i += 4) lum += (0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2]) / 255;
        lum /= d.length / 4;
        c.toBlob((b) => {
          URL.revokeObjectURL(url);
          if (!b) { reject(new Error('That photo couldn\'t be prepared. Try another one.')); return; }
          // iPhone Safari can't make WebP from a canvas; it makes JPEG fine, PNG as a last resort
          resolve({ blob: b, type: b.type, width: c.width, height: c.height, lum, url: URL.createObjectURL(b),
            small: Math.min(img.naturalWidth, img.naturalHeight) < 700 || (opt.wide && img.naturalWidth < 1600) });
        }, 'image/jpeg', 0.82);
      } catch (e) { URL.revokeObjectURL(url); reject(new Error('That photo couldn\'t be prepared. Try another one.')); }
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      if (/heic|heif/i.test(file.type || '') || /\.(heic|heif)$/i.test(file.name || '')) reject(new Error('This is an iPhone photo format this browser can\'t open. Choose it on your iPhone instead, or save it as a JPG first.'));
      else reject(new Error('That file couldn\'t be opened as a photo.'));
    };
    img.src = url;
  });
}

function pickFile(accept) {
  return new Promise((resolve) => {
    const inp = h('input', { type: 'file', accept, style: { position: 'fixed', left: '-9999px' } });
    inp.addEventListener('change', () => { resolve(inp.files && inp.files[0] ? inp.files[0] : null); inp.remove(); });
    document.body.append(inp);
    inp.click();
  });
}

/** Phone/computer crop preview with "Tap the important part". */
function focusPicker(src, sp, focus, onFocus) {
  const [phone, computer] = aspects(sp);
  let mode = 'phone';
  let f = { x: focus ? focus.x : 50, y: focus ? focus.y : 50 };
  const img = h('img', { src, alt: '', draggable: false });
  const dot = h('span.focus-dot');
  const pick = h('div.focus-pick', img, dot);
  const frame = h('div.crop-frame', h('img', { src, alt: '' }));
  const place = () => {
    dot.style.left = f.x + '%';
    dot.style.top = f.y + '%';
    frame.style.aspectRatio = String(mode === 'phone' ? phone : computer);
    frame.firstChild.style.objectPosition = `${f.x}% ${f.y}%`;
  };
  pick.addEventListener('click', (e) => {
    const r = img.getBoundingClientRect();
    f = { x: Math.round(Math.max(0, Math.min(100, ((e.clientX - r.left) / r.width) * 100))), y: Math.round(Math.max(0, Math.min(100, ((e.clientY - r.top) / r.height) * 100))) };
    place();
    onFocus(f);
  });
  const seg = h('div.seg', { role: 'radiogroup' },
    ['phone', 'computer'].map((m) => h('button', { type: 'button', role: 'radio', 'aria-checked': String(m === mode), text: m === 'phone' ? 'Phone' : 'Computer', onclick: (e) => { mode = m; seg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-checked', String(b === e.currentTarget))); place(); } })));
  place();
  return h('div.stack', h('p.help', { text: 'Tap the important part of the photo. The frame shows what visitors see.' }), pick, h('div.row', seg), frame);
}

export function photoScreen(key) {
  const path = parseKey(key);
  if (!path) return null;
  const sp = schemaPath(path);
  const f = field(sp);
  if (!f) return null;
  if (f.type === 'video') return videoScreen(path, sp, f);
  const [item, listSp] = itemOf(path);
  return photoEditor(path, sp, f, { title: f.label.replace(/: (tile )?photo$/, '') + (item ? ': ' + itemTitle(item, listSp) : '') });
}

function photoEditor(path, sp, f, opt) {
  const body = h('div.stack');
  const leaf = path[path.length - 1];
  const focusPath = path.slice(0, -1).concat([leaf + 'Focus']);
  const altPath = path.slice(0, -1).concat([leaf + 'Alt']);
  const hasAlt = !!field(schemaPath(altPath));
  const hasFocus = !!field(schemaPath(focusPath));
  const cur = getAt(S.draft, path);
  // where Done goes: the list item's screen, or all the photos
  const fallback = path.some((x) => typeof x === 'object') ? screenFor(path) : '#/photos';
  let fresh = null;
  let darken = OVER_TEXT.test(sp);
  let file = null;
  let focus = getAt(S.draft, focusPath) || null;
  let saving = null;
  let chooseBtn = null;
  const others = photoSpots().filter((s) => field(s.sp).type === 'photo' && s.v === cur && keyOf(s.path) !== keyOf(path) && cur);
  const draw = () => {
    clear(body);
    if (!fresh) {
      // the photo there now, then one clear thing to do
      if (cur) body.append(h('img.photo-current', { src: '/' + cur, alt: 'The photo there now', onclick: choose }));
      else body.append(h('p.callout.info', { text: 'No photo here yet.' }));
      body.append(chooseBtn = h('button.btn.primary', { type: 'button', onclick: choose }, icon('photo'), 'Choose a new photo'));
      body.append(h('p.small', { text: 'No house numbers, faces or license plates.' }));
      if (hasFocus && cur) {
        // moving the frame on the photo that's there now is a choice of its own, never a stray tap
        const det = h('details.section', h('summary', h('span.section-text', h('span.section-name', { text: 'Change what shows in the frame (optional)' }))),
          h('div.stack', focusPicker('/' + cur, sp, focus, (v) => { focus = v; saveFocus(); })));
        det.dataset.sec = 'Change what shows in the frame';
        body.append(det);
      }
    } else {
      if (hasFocus) body.append(h('h3', { text: 'Then tap the important part' }), focusPicker(fresh.url, sp, focus, (v) => { focus = v; }));
      else body.append(h('img.photo-big', { src: fresh.url, alt: 'The new photo' }));
      body.append(chooseBtn = h('button.btn', { type: 'button', onclick: choose }, icon('photo'), 'Pick a different photo'));
      body.append(h('p.small', { text: 'No house numbers, faces or license plates.' }));
      if (OVER_TEXT.test(sp)) {
        const sw = h('button.switch', { type: 'button', role: 'switch', 'aria-checked': String(darken), onclick: async () => { darken = !darken; if (file) { fresh = await processPhoto(file, { darken, wide: true }); } draw(); } }, h('span.knob'), h('span.switch-text', { text: darken ? 'On' : 'Off' }));
        body.append(h('div.field-box', h('div.field-head', h('span.field-label', { text: 'Match the site\'s look (darken a little)' })), h('p.help', { text: 'Words sit on top of this photo. Darkening keeps them easy to read.' }), sw));
        if (fresh.lum > 0.42) body.append(h('p.callout.warn', { text: 'This photo is bright where the words sit, so they may be hard to read. Keep the darkening on, or pick a darker photo.' }));
      }
      if (fresh.small) body.append(h('p.callout.warn', { text: OVER_TEXT.test(sp) ? 'This photo is small for a big spot (under 1600 pixels wide), so it may look soft on computers. A photo straight from the phone\'s camera works best.' : 'This photo is small, so it may look blurry on big screens.' }));
    }
    if (hasAlt) {
      const box = fieldBox(altPath, { label: 'What\'s in this photo? (for blind visitors and Google)' });
      if (fresh && JSON.stringify(getAt(S.draft, altPath)) === JSON.stringify(getAt(S.live.content, altPath))) box.prepend(h('p.callout.warn', { text: 'New photo: describe it here. This still describes the old photo.' }));
      body.append(box);
    }
    if (others.length) {
      body.append(h('h3', { text: 'This photo is also used here:' }));
      if (fresh) body.append(h('div.stack', others.map((o) => h('label.check', h('input', { type: 'checkbox', dataset: { key: keyOf(o.path) }, class: 'also-cb' }), ' Use the new photo there too: ' + labelFor(o)))));
      else body.append(h('ul.also-links', others.map((o) => h('li', h('a.linkish', { href: '#/photo/' + encodeURIComponent(keyOf(o.path)), text: labelFor(o) })))));
    }
    if (fresh) body.append(h('div.row.end', h('button.btn.ghost', { type: 'button', text: 'Cancel', onclick: () => { fresh = null; file = null; focus = getAt(S.draft, focusPath) || null; draw(); } }), h('button.btn.primary.big', { type: 'button', text: 'Use this photo', onclick: () => use() })));
    else body.append(h('div.row.end', h('button.btn.primary', { type: 'button', text: 'Done', onclick: () => back(fallback) })));
  };
  const saveFocus = () => {
    const v = focus && (focus.x !== 50 || focus.y !== 50) ? { x: focus.x, y: focus.y } : undefined;
    setAt(S.draft, focusPath, v);
    changed();
  };
  async function choose() {
    // the file picker opens straight from the tap (before anything else waits)
    const picked = await pickFile('image/jpeg,image/png,image/webp');
    if (!picked) return;
    const btn = chooseBtn;
    const label = btn ? [...btn.childNodes] : [];
    if (btn) { btn.disabled = true; btn.textContent = 'Getting the photo ready…'; }
    try {
      const next = await processPhoto(picked, { darken, wide: OVER_TEXT.test(sp) || /^site\.sharePicture$/.test(sp) });
      file = picked;
      fresh = next;
      focus = null;
      draw();
    } catch (e) {
      toast(e.message, 'bad', 7000);
      if (btn) { btn.disabled = false; clear(btn).append(...label); }
    }
  }
  /** Upload the new photo and use it. `quiet`: she has already moved on (it's saved in the background). */
  function use(quiet = false) {
    if (saving) return saving;
    const here = location.hash;
    const btn = body.querySelector('.btn.primary.big');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving the photo… you can keep working.'; }
    const pic = fresh;
    saving = (async () => {
      try {
        const r = await api('photo', { slot: sp }, { files: { photo: { blob: pic.blob, name: pic.type === 'image/png' ? 'photo.png' : 'photo.jpg' } } });
        const extra = [...body.querySelectorAll('.also-cb')].filter((cb) => cb.checked).map((cb) => parseKey(cb.dataset.key));
        for (const p of [path].concat(extra)) setAt(S.draft, p, r.path);
        if (opt.onUse) opt.onUse(r.path);
        if (hasFocus) saveFocus(); else changed();
        if (!quiet) toast('Photo saved. It goes live with your other changes.');
        fresh = null;
        file = null;
        // only if she's still here: never moved somewhere she didn't ask to go
        if (location.hash === here && body.isConnected) back(fallback);
        else if (location.hash === '#/photos') render(); // the grid shows the new photo
      } catch (e) {
        toast(e.message || 'The photo didn\'t upload. Try again.', 'bad', 6000);
        if (btn && btn.isConnected) { btn.disabled = false; btn.textContent = 'Use this photo'; }
      } finally {
        saving = null;
      }
    })();
    return saving;
  }
  draw();
  return {
    title: opt.title || 'Photo',
    body,
    back: fallback,
    // she picked a photo and left without "Use this photo": keep it rather than lose it
    leave: () => {
      if (!fresh || saving) return;
      use(true);
      toast('Your new photo was kept. It goes live with your other changes.', 'ok', 5000);
    },
  };
}

function labelFor(spot) {
  const f = field(spot.sp);
  const page = (PAGE_OF.find(([re]) => re.test(spot.sp)) || [0, ''])[1];
  const [item, listSp] = itemOf(spot.path);
  // inside a list the item says which one it is ("Services page: Supplements")
  const what = item ? itemTitle(item, listSp) : f.label.replace(/: (tile )?photo$/, '');
  return (page ? page + ' page: ' : '') + what;
}

function videoScreen(path, sp, f) {
  const tile = sp.startsWith('services.coverage.tiles');
  const parentPath = path.slice(0, -1);
  const parent = getAt(S.draft, parentPath) || {};
  const showsPhoto = tile ? !parent.video : !!parent.photo;
  const body = h('div.stack');
  const base = f.label.replace(/: (background video: video|tile: video|video)$/, '');
  const title = /video/i.test(base) ? base : base + ': video';
  const storm = sp === 'home.top.video.video';
  if (storm) body.append(h('p.help', { text: 'Swapping the storm video for a photo also turns off the rain and lightning drawn over it.' }));
  const poster = tile ? (parent.video ? parent.poster : parent.photo) : (parent.photo || parent.poster);
  if (poster) body.append(h('img.photo-big', { src: '/' + poster, alt: '' }));
  body.append(h('p.status-card', h('strong', { text: showsPhoto ? 'Your photo shows instead of the video.' : storm ? 'The storm video is showing now.' : 'The video is showing now.' })));
  let inner = null;
  const usePhoto = () => {
    const photoPath = parentPath.concat(['photo']);
    inner = photoEditor(photoPath, schemaPath(photoPath), field(schemaPath(photoPath)) || { label: 'Photo' }, {
      title: 'Photo instead of the video',
      onUse: () => {
        if (tile) {
          const cur = getAt(S.draft, parentPath);
          if (cur.video) { setAt(S.draft, parentPath.concat(['videoWas']), cur.video); setAt(S.draft, parentPath.concat(['video']), ''); }
        }
      },
    });
    const host = document.querySelector('main.screen');
    clear(host).append(inner.body);
    document.querySelector('.bar h1').textContent = inner.title;
  };
  const putBack = () => {
    if (tile) {
      const cur = getAt(S.draft, parentPath);
      setAt(S.draft, parentPath.concat(['video']), cur.videoWas || getAt(S.live.content, parentPath.concat(['videoWas'])) || '');
      setAt(S.draft, parentPath.concat(['videoWas']), undefined);
    } else {
      setAt(S.draft, parentPath.concat(['photo']), undefined);
      setAt(S.draft, parentPath.concat(['photoFocus']), undefined);
    }
    changed();
    toast('The video is back. It goes live with your other changes.');
    back('#/photos');
  };
  // one main thing to do: while the video plays, swap it; while a photo shows, bring the video back
  body.append(showsPhoto
    ? h('div.stack', h('button.btn.primary', { type: 'button', text: 'Put the video back', onclick: putBack }), h('button.btn', { type: 'button', text: 'Use a different photo', onclick: usePhoto }))
    : h('div.stack', h('button.btn.primary', { type: 'button', text: 'Use my photo instead', onclick: usePhoto })));
  return { title, body, back: '#/photos', leave: () => { if (inner && inner.leave) inner.leave(); } };
}

// ------------------------------------------------------------------ contracts

let pdfjs = null;
async function loadPdfJs() {
  if (pdfjs) return pdfjs;
  pdfjs = await import('./vendor/pdfjs/pdf.min.mjs');
  pdfjs.GlobalWorkerOptions.workerSrc = new URL('./vendor/pdfjs/pdf.worker.min.mjs', import.meta.url).toString();
  return pdfjs;
}
/** Text of every page (up to 30) and page 1 drawn on a canvas. */
export async function readPdf(data, withPage = true) {
  const lib = await loadPdfJs();
  const doc = await lib.getDocument({ data, isEvalSupported: false, disableFontFace: true, standardFontDataUrl: new URL('./vendor/pdfjs/standard_fonts/', import.meta.url).toString(), useSystemFonts: false }).promise;
  let text = '';
  let filled = false;
  for (let i = 1; i <= Math.min(doc.numPages, 30); i++) {
    const page = await doc.getPage(i);
    const tc = await page.getTextContent();
    text += tc.items.map((x) => x.str).join(' ') + '\n';
    try {
      const ann = await page.getAnnotations();
      if (ann.some((a) => a.fieldValue && String(a.fieldValue).trim() !== '' && a.fieldValue !== 'Off')) filled = true;
    } catch (e) { /* */ }
  }
  let canvas = null;
  if (withPage) {
    const p1 = await doc.getPage(1);
    const vp1 = p1.getViewport({ scale: 1 });
    const scale = Math.min(2, 680 / vp1.width);
    const vp = p1.getViewport({ scale });
    canvas = document.createElement('canvas');
    canvas.width = Math.round(vp.width);
    canvas.height = Math.round(vp.height);
    await p1.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
  }
  return { text, pages: doc.numPages, filled, canvas };
}

function notPdf(bytes, name) {
  const head = Array.from(bytes.slice(0, 5)).map((b) => String.fromCharCode(b)).join('');
  if (head === '%PDF-') return '';
  const ext = (name.split('.').pop() || '').toLowerCase();
  if (['doc', 'docx'].includes(ext) || (bytes[0] === 0x50 && bytes[1] === 0x4b) || (bytes[0] === 0xd0 && bytes[1] === 0xcf)) return 'This is a Word file. Ask John to send it as a PDF.';
  if (['jpg', 'jpeg', 'png', 'heic', 'webp'].includes(ext) || (bytes[0] === 0xff && bytes[1] === 0xd8)) return 'This is a photo, not a PDF. Ask John to send the contract as a PDF.';
  if (ext === 'pages') return 'This is a Pages file. Ask John to send it as a PDF.';
  return 'This isn\'t a PDF. Ask John to send the contract as a PDF.';
}

// not "signed by": the blank Indiana contract says "signed by the other party"
function looksSigned(text) {
  return /(envelope id|transaction id|electronically signed|docusign|acrobat sign|adobe sign|signature verified|\/s\/\s*[a-z])/i.test(text);
}

export function iphoneHelp() {
  return h('details.help-card',
    h('summary', { text: 'How to save a PDF from an email on iPhone' }),
    h('ol', h('li', { text: 'In Gmail or Mail, open the email and tap the PDF.' }),
      h('li', { text: 'Tap Share (the square with the arrow), then "Save to Files".' }),
      h('li', { text: 'Pick "On My iPhone" and tap Save.' }),
      h('li', { text: 'Come back here, tap "Choose the PDF", then "Browse" or "Recents", and pick it.' })));
}

const MB = 1048576;
const mb = (n) => { const x = n / MB; return x >= 10 ? String(Math.round(x)) : x.toFixed(1).replace(/\.0$/, ''); };
/** The biggest upload the website takes, in bytes (the boot value may come as bytes, MB or "64M"). */
function uploadMax() {
  const v = S.boot.uploadMax;
  const n = parseFloat(v);
  if (!(n > 0)) return 15 * MB;
  if (typeof v === 'string' && /[kmg]\s*b?$/i.test(v.trim())) return n * { k: 1024, m: MB, g: 1024 * MB }[v.trim().replace(/b$/i, '').slice(-1).toLowerCase()];
  return n < 4096 ? n * MB : n;
}

/** Old phone numbers and emails, as patterns: from the last change that went live, and the ones she's changing now. */
function oldDetails() {
  const now = S.draft.site || {};
  const digits = (p) => String(p || '').replace(/\D/g, '').replace(/^1(?=\d{10}$)/, '');
  const phones = [now.phone, now.phone2].map(digits);
  const emails = [String(now.email || '').trim().toLowerCase()];
  const out = [];
  const addPhone = (p, current) => {
    const d = digits(p);
    if (d.length !== 10 || phones.includes(d) || out.some((o) => o.label === p)) return;
    out.push({ label: p, now: current, re: new RegExp(`\\(?${d.slice(0, 3)}\\)?[\\s.-]*${d.slice(3, 6)}[\\s.-]*${d.slice(6)}`) });
  };
  const addEmail = (e, current) => {
    const t = String(e || '').trim();
    if (!t.includes('@') || emails.includes(t.toLowerCase()) || out.some((o) => o.label === t)) return;
    out.push({ label: t, now: current, re: new RegExp(t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i') });
  };
  if (S.prefs.alsoAt > 0) {
    for (const x of String(S.prefs.alsoOld || '').split(', ')) {
      if (x.replace(/\D/g, '').length === 10) addPhone(x, now.phone);
      else if (x.includes('@')) addEmail(x, now.email);
    }
  }
  const was = (S.live.content || {}).site || {};
  if ((was.phone || '') !== (now.phone || '')) addPhone(was.phone, now.phone);
  if ((was.phone2 || '') !== (now.phone2 || '')) addPhone(was.phone2, now.phone2 || now.phone);
  if ((was.email || '') !== (now.email || '')) addEmail(was.email, now.email);
  return out;
}

/**
 * Pick a new contract PDF for a state, check it, and save it into her changes. Starts the file
 * picker straight from the tap. opts.body: the screen it was started from (redraws only while
 * it's still showing); opts.draw: redraw it; opts.busy(text): show what's happening.
 */
export async function replaceContract(stateId, opts = {}) {
  const path = ['states', { id: stateId }];
  const st = getAt(S.draft, path);
  if (!st) return;
  const name = S.boot.states[st.code] || 'this state';
  const here = () => !(opts.body && !opts.body.isConnected);
  const redraw = () => { if (here() && opts.draw) opts.draw(); };
  const busy = (t) => { if (here() && opts.busy) opts.busy(t); };
  const file = await pickFile('application/pdf,.pdf');
  if (!file) return;
  const max = uploadMax();
  if (file.size > max) { await sheet('Too big', `This PDF is ${mb(file.size)} MB; the website takes up to ${mb(max)} MB. Ask John to send a smaller copy.`, [{ label: 'OK', value: true, kind: 'primary' }]); return; }
  const buf = new Uint8Array(await file.arrayBuffer());
  const bad = notPdf(buf, file.name || '');
  if (bad) { await sheet('Not a PDF', bad, [{ label: 'OK', value: true, kind: 'primary' }]); return; }
  busy('Reading the PDF…');
  let info;
  try { info = await readPdf(buf.slice()); } catch (e) { await sheet('Couldn\'t read it', 'This PDF couldn\'t be opened. Ask John for another copy.', [{ label: 'OK', value: true }]); redraw(); return; }
  const notes = [];
  const others = Object.entries(S.boot.states).filter(([c, n]) => c !== st.code && new RegExp('\\b' + n + '\\b', 'i').test(info.text)).map(([, n]) => n);
  const mine = new RegExp('\\b' + name + '\\b', 'i').test(info.text);
  if (others.length && !mine) notes.push(`This contract mentions ${others.join(', ')}, not ${name}.`);
  else if (others.length) notes.push(`It also mentions ${others.join(', ')}.`);
  if (info.filled) notes.push('Some boxes in this PDF are filled in.');
  if (looksSigned(info.text)) notes.push('This looks like a signed copy.');
  // only details the website used to show count, so a state's own hotline numbers don't
  const flat = info.text.replace(/\s+/g, ' ');
  for (const o of oldDetails()) if (o.re.test(flat)) notes.push(`This contract shows ${o.label}, but the website now uses ${o.now}. Ask John for an updated one.`);
  const preview = h('div.stack', notes.map((n) => h('p.callout.warn', { text: n })), info.canvas ? h('div.pdf-page', info.canvas) : null);
  const ok = await sheet(`Is this the BLANK ${name} contract, with no customer's details?`, preview, [{ label: 'Yes, it\'s blank', value: true, kind: 'primary' }, { label: 'No, pick another', value: false, kind: 'ghost' }]);
  if (!ok) { redraw(); return; }
  busy('Saving the contract…');
  let r;
  try { r = await api('contract', { state: st.code }, { files: { pdf: { blob: file, name: file.name || 'contract.pdf' } } }); } catch (e) { toast(e.message, 'bad', 6000); redraw(); return; }
  setAt(S.draft, path.concat(['pdfV']), r.v);
  if (!getAt(S.draft, path.concat(['pdf']))) setAt(S.draft, path.concat(['pdf']), r.permanent);
  changed();
  if (!here()) { toast('Contract saved. It goes live with your other changes.'); return; }
  const esign = await sheet('Did the e-sign version change too?', `If John also changed the ${name} agreement in Adobe, it may have a new link.`, [{ label: 'No', value: 'no', kind: 'primary' }, { label: 'Yes, there\'s a new link', value: 'yes' }]);
  if (esign === 'yes' && here()) { S.reveal = keyOf(path.concat(['esign'])); go(`#/item/states/${stateId}`); return; }
  toast('Contract saved. It goes live with your other changes.');
  redraw();
}

export function contractScreen(stateId) {
  const path = ['states', { id: stateId }];
  const st = getAt(S.draft, path);
  if (!st) return null;
  const name = S.boot.states[st.code] || 'this state';
  const body = h('div.stack');
  const versions = h('div.stack');
  const draw = () => {
    clear(body);
    const cur = getAt(S.draft, path);
    const livePath = cur.pdf ? '/' + cur.pdf : null;
    const pending = cur.pdfV && cur.pdfV !== (getAt(S.live.content, path) || {}).pdfV;
    body.append(h('div.status-card', h('strong', { text: cur.pdf ? `${name} contract` : `No ${name} contract PDF yet` }),
      pending ? h('span.pill.new', { text: 'New, not live yet' }) : null,
      cur.pdf ? h('a.linkish', { href: pending ? '/edit/p/' + cur.pdf : livePath, target: '_blank', rel: 'noopener', text: 'View it' }) : null));
    body.append(iphoneHelp());
    body.append(h('button.btn.primary.big', { type: 'button', onclick: () => replaceContract(stateId, { body, draw: () => { draw(); loadVersions(); }, busy: (t) => clear(body).append(h('p', { text: t })) }) }, icon('pdf'), cur.pdf ? 'Replace with a new PDF' : 'Choose the PDF'));
    body.append(versions);
  };
  const loadVersions = async () => {
    try {
      const r = await api('contracts', { state: st.code }, { get: true });
      clear(versions);
      if (!r.versions.length) { versions.append(h('h3', { text: 'Earlier contracts' }), h('p.small', { text: 'None yet. Each time you replace the contract, the old one is kept here.' })); return; }
      // waiting to go live / on the website now / earlier
      const cur = getAt(S.draft, path) || {};
      const livePdfV = (getAt(S.live.content, path) || {}).pdfV;
      const groups = [['Waiting to go live', []], ['On the website now', []], ['Earlier', []]];
      for (const v of r.versions) {
        if (cur.pdfV && v.v === cur.pdfV && cur.pdfV !== livePdfV) groups[0][1].push(v);
        else if (livePdfV ? v.v === livePdfV : v.first) groups[1][1].push(v);
        else groups[2][1].push(v);
      }
      for (const [head, list] of groups) {
        if (!list.length) continue;
        versions.append(h('h3', { text: head }), h('ul.versions', list.map((v) => h('li', h('span', { text: v.when + (v.first ? ' (before the editor)' : v.by ? ' by ' + v.by : '') }), h('a.linkish', { href: '/edit/api/contract-file?v=' + v.v, target: '_blank', rel: 'noopener', text: 'View' })))));
      }
    } catch (e) { clear(versions).append(h('h3', { text: 'Earlier contracts' }), h('p.small', { text: 'Needs a connection.' })); }
  };
  draw();
  loadVersions();
  return { title: name + ' contract', body };
}

/** After a phone or email change: do the contract PDFs still print the old ones? */
export function contractsCheckScreen() {
  const body = h('div.stack', h('p', { text: 'Reading the contracts…' }));
  const ready = (async () => {
    // the details she's changing now, or (once that went live) the ones it replaced
    const old = oldDetails();
    const found = [];
    for (const st of visibleStates()) {
      if (!st.pdf) continue;
      try {
        const res = await fetch(st.pdfV ? '/edit/p/' + st.pdf : '/' + st.pdf, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('not found');
        const info = await readPdf(new Uint8Array(await res.arrayBuffer()), false);
        const text = info.text.replace(/\s+/g, ' ');
        for (const o of old) if (o.re.test(text)) found.push({ st, what: o.label });
        if (/nelsonj4@hotmail\.com/i.test(text) && !found.some((x) => x.st === st)) found.push({ st, what: 'nelsonj4@hotmail.com' });
      } catch (e) { found.push({ st, what: null }); }
    }
    clear(body);
    if (!old.length) body.append(h('p.callout.info', { text: 'The phone and email haven\'t changed, so there\'s nothing to check.' }));
    if (!found.length && old.length) body.append(h('p.callout.ok', { text: 'None of the contracts show the old details.' }));
    for (const f of found) {
      if (!f.what) {
        body.append(h('div.callout.warn', h('p', { text: `The ${f.st.name} contract couldn't be read. The connection may have dropped.` }),
          h('button.btn.small', { type: 'button', text: 'Try again', onclick: () => render() })));
        continue;
      }
      body.append(h('div.callout.warn', h('p', { text: `The ${f.st.name} contract still shows ${f.what}.` }),
        h('p.small', { text: 'Ask John for an updated PDF, then replace it.' }),
        h('a.btn.small', { href: '#/contract/' + f.st._id, text: 'Replace it' })));
    }
  })();
  return { title: 'Contracts', body, ready };
}
