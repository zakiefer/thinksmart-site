// Leads (kept 90 days, photos 14), "Where leads are emailed" (a new address takes over only
// after someone clicks the link in a test lead), the health lines on the start screen, and
// "Repair the editor". Lead text is shown as plain text only.
import { S, h, icon, clear, api, toast, sheet, go, timeOf, render } from './core.js';
import { kb } from './fields.js';

/**
 * While a button's request runs: greyed out, "One moment…", and a second tap does nothing.
 * Put back afterwards unless the screen was redrawn meanwhile.
 */
async function busy(el, fn, say = el) {
  const kids = [...say.childNodes];
  el.disabled = true;
  el.setAttribute('aria-busy', 'true');
  say.textContent = 'One moment…';
  try {
    return await fn();
  } finally {
    if (el.isConnected) { el.disabled = false; el.removeAttribute('aria-busy'); say.replaceChildren(...kids); }
  }
}

/** A screen couldn't load: say why, with a way to try again. */
function loadFailed(body, e) {
  clear(body).append(h('div.callout.warn', h('p', { text: e.message }), h('button.btn', { type: 'button', text: 'Try again', onclick: () => render() })));
}

const KIND_ICON = { claim: 'pdf', contact: 'chat', chat: 'chat', photos: 'photo' };
const MAIL_WORDS = {
  sent: ['ok', 'Emailed'], failed: ['bad', 'Email didn\'t go out'], bounced: ['bad', 'Didn\'t reach your email'],
  held: ['warn', 'Kept here (not emailed)'], waiting: ['warn', 'Being emailed'], unknown: ['', ''],
};

/** The status card on the start screen: last change, last lead, and anything to act on. */
export function healthCard() {
  const card = h('div.status-card.health');
  const l = S.live;
  const by = l.by ? (l.by === S.me.id ? 'you' : S.names[l.by] || 'someone') : null;
  const draw = (st) => {
    clear(card);
    const bad = st && st.lines.some((x) => x.level === 'bad');
    card.append(h('div.health-top', h('span.dot' + (bad ? '.bad' : '.ok')),
      h('span', { text: (bad ? 'Website needs a look' : 'Website working') + (l.at && by ? ` · last change ${whenText(l.at)} by ${by}` : '') })));
    const last = (st && st.lastLead) || S.boot.leadsLast;
    card.append(h('a.lead-line', { href: '#/leads' }, icon('chat'), h('span', { text: last ? 'Last lead received ' + last.when : 'No leads yet' }), icon('chev')));
    for (const line of (st && st.lines) || []) {
      card.append(line.href ? h('a.health-line.' + line.level, { href: line.href, text: line.text }) : h('p.health-line.' + line.level, { text: line.text }));
    }
    if (S.offline) card.append(h('p.small.warn-text', { text: 'Not connected right now. Your changes are kept on this phone and sent when you\'re back online.' }));
  };
  draw(null);
  api('health', {}).then(draw).catch(() => {}); // a POST: it may put files back
  return card;
}
function whenText(sec) {
  const d = new Date(sec * 1000);
  const fmt = (o) => new Intl.DateTimeFormat('en-US', { timeZone: 'America/Chicago', ...o }).format(d);
  const today = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Chicago', year: 'numeric', month: '2-digit', day: '2-digit' });
  if (today.format(d) === today.format(new Date())) return 'today ' + timeOf(sec);
  if (Date.now() - d < 6 * 86400000) return fmt({ weekday: 'short' }) + ' ' + timeOf(sec);
  return fmt({ month: 'short', day: 'numeric' }) + ' ' + timeOf(sec);
}

export function leadsScreen() {
  const body = h('div.stack', h('p.small', { text: 'Loading…' }));
  const ready = api('leads').then((r) => {
    clear(body);
    body.append(h('p.help', { text: `Every claim, message and chat from the website is kept here for ${r.keepDays} days (photos ${r.photoDays} days), even if an email doesn't arrive.` }));
    if (r.bounced) body.append(h('p.callout.bad', { text: `${r.bounced} lead${r.bounced === 1 ? '' : 's'} didn't reach your email. They're marked below.` }));
    if (!r.leads.length) { body.append(h('div.empty', icon('chat'), h('p', { text: 'No leads yet.' }))); return; }
    body.append(h('ul.leads', r.leads.map((x) => {
      const [lvl, words] = MAIL_WORDS[x.mail] || MAIL_WORDS.unknown;
      return h('li', h('a.lead-item', { href: '#/lead/' + x.id },
        icon(KIND_ICON[x.kind] || 'chat'),
        h('span.lead-text',
          h('span.lead-top', h('strong', { text: x.name || x.phone || x.email || x.what }), h('span.small', { text: x.when })),
          h('span.small', { text: [x.what, x.caseNo, x.phone && x.name ? x.phone : '', x.photos ? x.photos + ' photo' + (x.photos === 1 ? '' : 's') : ''].filter(Boolean).join(' · ') }),
          x.snippet ? h('span.lead-snippet', { text: x.snippet }) : null,
          words ? h('span.pill.' + lvl, { text: words }) : null),
        icon('chev')));
    })));
  }).catch((e) => loadFailed(body, e));
  return { title: 'Leads', body, ready };
}

export function leadScreen(id) {
  const body = h('div.stack', h('p.small', { text: 'Loading…' }));
  const draw = (d) => {
    clear(body);
    const [lvl, words] = MAIL_WORDS[d.mail] || MAIL_WORDS.unknown;
    body.append(h('p.crumb', { text: d.what + ' · ' + d.when + (d.caseNo ? ' · ' + d.caseNo : '') }));
    if (words) body.append(h('p.callout.' + (lvl === 'ok' ? 'ok' : lvl === 'bad' ? 'bad' : 'warn'), { text: words + (d.mailTo && d.mailTo.length ? ' to ' + d.mailTo.join(', ') : '') + (d.mailAt ? ' (' + d.mailAt + ')' : '') }));
    const acts = h('div.row.wrap');
    const digits = (d.phone || '').replace(/[^\d+]/g, '');
    if (digits.replace(/\D/g, '').length >= 10) acts.append(h('a.btn.primary', { href: 'tel:' + digits }, icon('phone'), 'Call ' + d.phone));
    if (d.email) acts.append(h('a.btn', { href: 'mailto:' + encodeURIComponent(d.email).replace('%40', '@') }, 'Email ' + d.email));
    if (acts.childNodes.length) body.append(acts);
    const dl = h('dl.lead-fields');
    for (const [k, v] of d.rows) dl.append(h('dt', { text: k }), h('dd', { text: v }));
    body.append(dl);
    if (d.photoFiles && d.photoFiles.length) {
      body.append(h('h3', { text: 'Photos' }), h('div.photo-grid', d.photoFiles.map((f) => h('a.photo-cell', { href: `/edit/api/lead-photo?id=${encodeURIComponent(d.id)}&file=${encodeURIComponent(f)}`, target: '_blank', rel: 'noopener' },
        h('img', { src: `/edit/api/lead-photo?id=${encodeURIComponent(d.id)}&file=${encodeURIComponent(f)}`, alt: 'Photo ' + f, loading: 'lazy' })))));
    }
    body.append(h('button.btn', { type: 'button', text: 'Email it to me again', onclick: (e) => busy(e.currentTarget, async () => {
      try { const r = await api('lead-resend', { id: d.id }); toast('Sent again.'); draw(r.lead); } catch (x) { toast(x.message, 'bad', 6000); }
    }) }));
    body.append(h('p.small', { text: `Kept until ${new Date((d.at + 90 * 86400) * 1000).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}. Visitor's address: ${d.ip || 'unknown'}.` }));
    if (d.mail === 'bounced') api('lead-seen', { id: d.id }).catch(() => {});
  };
  const ready = api('lead', { id }, { get: true }).then((r) => draw(r.lead)).catch((e) => loadFailed(body, e));
  return { title: 'Lead', back: '#/leads', body, ready };
}

/** Business details: where leads are emailed (1–3 addresses, each confirmed by a test lead). */
export function leadAddresses() {
  const box = h('section.field-box', { dataset: { key: 'leadTo' } });
  const draw = (list) => {
    clear(box);
    box.append(h('div.field-head', h('span.field-label', { text: 'Where leads are emailed' })),
      h('p.help', { text: 'Never shown on the website. A new address gets a test email first; leads start going there after someone taps the link in it.' }));
    const ul = h('ul.addresses');
    for (const a of list) {
      ul.append(h('li', h('span', h('strong', { text: a.email }), h('span.small', { text: a.state === 'confirmed' ? ' · Confirmed' : ' · Waiting: tap the link in the test email' })),
        h('div.row',
          a.state !== 'confirmed' ? h('button.btn.small', { type: 'button', text: 'Send the test again', onclick: (e) => busy(e.currentTarget, async () => {
            try { draw((await api('lead-address-resend', { email: a.email })).to); toast('Test email sent to ' + a.email + '.'); } catch (x) { toast(x.message, 'bad', 5000); }
          }) }) : null,
          h('button.btn.small.ghost', { type: 'button', text: 'Remove', onclick: async (e) => {
            const btn = e.currentTarget;
            const ok = await sheet('Stop sending leads to ' + a.email + '?', 'Leads stay in the editor either way.', [{ label: 'Remove', value: true, kind: 'danger' }, { label: 'Keep it', value: false, kind: 'ghost' }]);
            if (!ok) return;
            await busy(btn, async () => {
              try { draw((await api('lead-address-remove', { email: a.email })).to); } catch (x) { toast(x.message, 'bad', 6000); }
            });
          } }))));
    }
    if (!list.length) ul.append(h('li.small', { text: 'Leads go to thinksmartpublicadjusting@gmail.com until an address is added here.' }));
    box.append(ul);
    if (list.length < 3) {
      const inp = h('input.input', { ...kb('email'), placeholder: 'name@example.com', 'aria-label': 'Add an address for leads' });
      box.append(h('div.row', inp, h('button.btn', { type: 'button', text: 'Add', onclick: (e) => busy(e.currentTarget, async () => {
        try { draw((await api('lead-address-add', { email: inp.value })).to); toast('Test email sent. Tap the link in it to confirm.', 'ok', 6000); } catch (x) { toast(x.message, 'bad', 6000); }
      }) })));
    }
  };
  draw(S.boot.leadTo || []);
  return box;
}

/** More → the editor's own "fix it" button (the server puts its screens and the pages back). */
export async function repairEditor(e) {
  const btn = e && e.currentTarget;
  const ok = await sheet('Fix the editor?', 'If a screen or a page looks wrong, this puts the editor and the website\'s pages back the way they should be. Your words, photos, history and logins aren\'t touched.', [{ label: 'Fix it', value: true, kind: 'primary' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
  if (!ok) return;
  const run = async () => {
    try {
      const r = await api('repair', {});
      await sheet('Done', r.fixed ? 'Everything is back the way it should be.' : 'Everything was already fine.', [{ label: 'OK', value: true, kind: 'primary' }]);
      location.reload();
    } catch (x) { toast(x.message, 'bad', 6000); }
  };
  await (btn instanceof HTMLButtonElement ? busy(btn, run) : run());
}
