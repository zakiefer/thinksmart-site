// People (who can edit, reset the other person's password, remove a person, rescue codes),
// "Words I taught", and the "How do I…?" picture cards.
import { S, h, icon, clear, api, toast, sheet, go, render } from './core.js';
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

/** A screen's list couldn't load: say why, with a way to try again. */
function loadFailed(body, e) {
  clear(body).append(h('div.callout.warn', h('p', { text: e.message }), h('button.btn', { type: 'button', text: 'Try again', onclick: () => render() })));
}

// ------------------------------------------------------------------ people

export function peopleScreen() {
  const body = h('div.stack', h('p.small', { text: 'Loading…' }));
  const draw = (people) => {
    clear(body);
    body.append(h('p.help', { text: 'Everyone here can change everything. People with "Gets alert emails" are emailed, with an Undo button, when a phone number, the email, an e-sign link, a contract, a social link or a person changes.' }));
    for (const p of people) {
      const card = h('section.person' + (p.removed ? '.is-hidden' : ''),
        h('div.person-head', icon('people'), h('div', h('strong', { text: p.name + (p.me ? ' (you)' : '') }), h('span.small', { text: `Username: ${p.username} · ${p.email}` }))),
        p.removed ? h('p.callout.warn', { text: 'Removed ' + p.removed + '. Can\'t sign in.' }) : null,
        p.devices.length && !p.removed ? h('p.small', { text: 'Signed in on: ' + p.devices.slice(0, 3).map((d) => `${d.what} (${d.last})`).join(', ') }) : null);
      const acts = h('div.row.wrap');
      if (p.removed) {
        acts.append(h('button.btn.small', { type: 'button', text: 'Let them back in', onclick: (e) => busy(e.currentTarget, async () => {
          try { draw((await api('people-restore', { id: p.id })).people); toast(p.name + ' can sign in again.'); } catch (x) { toast(x.message, 'bad', 5000); }
        }) }));
      } else {
        const words = h('span', { text: 'Gets alert emails' });
        acts.append(h('label.check', h('input', { type: 'checkbox', checked: p.alerts, onchange: (e) => {
          const box = e.currentTarget;
          return busy(box, async () => {
            try { draw((await api('people-update', { id: p.id, alerts: box.checked })).people); toast('Saved.'); } catch (x) { box.checked = !box.checked; toast(x.message, 'bad', 5000); }
          }, words);
        } }), words));
        acts.append(h('button.btn.small', { type: 'button', text: 'Change email', onclick: async (e) => {
          const btn = e.currentTarget;
          const inp = h('input.input', { ...kb('email'), value: p.email });
          const ok = await sheet('Email for ' + p.name, h('div.stack', h('p.small', { text: 'Sign-in codes go to this address.' }), inp), [{ label: 'Save', value: true, kind: 'primary' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
          if (!ok) return;
          await busy(btn, async () => {
            try { draw((await api('people-update', { id: p.id, email: inp.value })).people); toast('Saved.'); } catch (x) { toast(x.message, 'bad', 5000); }
          });
        } }));
        if (p.me) {
          acts.append(h('button.btn.small', { type: 'button', text: 'Change my password', onclick: (e) => changePassword(p, e.currentTarget) }));
          acts.append(h('button.btn.small', { type: 'button', text: 'Make new rescue codes', onclick: (e) => rescue(e.currentTarget) }));
        } else {
          acts.append(h('button.btn.small', { type: 'button', text: `Reset ${p.name}'s password`, onclick: (e) => busy(e.currentTarget, async () => {
            try {
              const r = await api('people-reset', { id: p.id });
              await sheet(`Code for ${p.name}`, h('div.stack', h('p.code-big', { text: r.code.slice(0, 3) + ' ' + r.code.slice(3) }),
                h('p', { text: `Give ${p.name} this code (it was also emailed to them). On the sign-in screen they tap "Forgot your password?", then "I have a code", and type it with a new password. It works for ${r.hours} hours.` })), [{ label: 'Done', value: true, kind: 'primary' }]);
            } catch (x) { toast(x.message, 'bad', 5000); }
          }) }));
          acts.append(h('button.btn.small.danger', { type: 'button', text: 'Remove ' + p.name, onclick: async (e) => {
            const btn = e.currentTarget;
            const ok = await sheet(`Remove ${p.name}?`, `${p.name} is signed out everywhere at once and their rescue codes stop working. You can let them back in within 30 days. If they're leaving, also change the GoDaddy and Gmail passwords.`, [{ label: 'Remove', value: true, kind: 'danger' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
            if (!ok) return;
            await busy(btn, async () => {
              try { draw((await api('people-remove', { id: p.id })).people); toast(p.name + ' was removed.'); } catch (x) { toast(x.message, 'bad', 5000); }
            });
          } }));
        }
      }
      card.append(acts);
      body.append(card);
    }
    body.append(h('button.btn.add', { type: 'button', onclick: (e) => addPerson(e.currentTarget) }, icon('plus'), 'Add a person'));
  };
  const changePassword = async (p, btn) => {
    const user = h('input', { type: 'text', autocomplete: 'username', value: p.username, hidden: true, readOnly: true });
    const cur = h('input.input', { type: 'password', autocomplete: 'current-password', 'aria-label': 'Current password' });
    const nw = h('input.input', { type: 'password', autocomplete: 'new-password', minLength: 8, 'aria-label': 'New password' });
    const form = h('form.stack', { onsubmit: (e) => e.preventDefault() }, user, h('label.part', h('span', { text: 'Current password' }), cur), h('label.part', h('span', { text: 'New password' }), nw), h('p.small', { text: 'At least 8 characters. Your iPhone can suggest a strong one and save it.' }));
    const ok = await sheet('Change my password', form, [{ label: 'Save', value: true, kind: 'primary' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
    if (!ok) return;
    await busy(btn, async () => {
      try { await api('password', { current: cur.value, new: nw.value }); toast('Password changed. Other phones were signed out.'); } catch (e) { toast(e.message, 'bad', 6000); }
    });
  };
  const rescue = async (btn) => {
    const ok = await sheet('Make new rescue codes?', 'Your old rescue codes stop working. You\'ll see two new ones once: write one on the emergency sheet and save the other in your Passwords app.', [{ label: 'Make new codes', value: true, kind: 'primary' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
    if (!ok) return;
    await busy(btn, async () => {
      try {
        const r = await api('people-rescue', {});
        await sheet('Your new rescue codes', h('div.stack', r.codes.map((c) => h('p.code-big', { text: c })), h('p', { text: 'Each works once, on the sign-in screen under "Forgot your password?" → "Use a rescue code". They won\'t be shown again.' })), [{ label: 'I\'ve saved them', value: true, kind: 'primary' }]);
      } catch (e) { toast(e.message, 'bad', 5000); }
    });
  };
  const addPerson = async (btn) => {
    const name = h('input.input', { ...kb('name'), 'aria-label': 'Name' });
    const user = h('input.input', { autocapitalize: 'none', autocorrect: 'off', spellcheck: false, 'aria-label': 'Username' });
    const mail = h('input.input', { ...kb('email'), 'aria-label': 'Email' });
    const ok = await sheet('Add a person', h('div.stack', h('label.part', h('span', { text: 'Name' }), name), h('label.part', h('span', { text: 'Username (private, not their email)' }), user), h('label.part', h('span', { text: 'Email' }), mail),
      h('p.small', { text: 'They get an email with a code to choose their own password.' })), [{ label: 'Add', value: true, kind: 'primary' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
    if (!ok) return;
    await busy(btn, async () => {
      try {
        const r = await api('people-add', { name: name.value, username: user.value, email: mail.value });
        draw(r.people);
        await sheet('Added', h('div.stack', h('p', { text: `${name.value} was emailed a code. You can also give it to them:` }), h('p.code-big', { text: r.code.slice(0, 3) + ' ' + r.code.slice(3) }), h('p.small', { text: 'On the sign-in screen: "Forgot your password?" → "I have a code". It works for 24 hours.' })), [{ label: 'Done', value: true, kind: 'primary' }]);
      } catch (e) { toast(e.message, 'bad', 6000); }
    });
  };
  const ready = api('people').then((r) => draw(r.people)).catch((e) => loadFailed(body, e));
  return { title: 'People', body, ready };
}

// ------------------------------------------------------------------ words I taught

export function wordsScreen() {
  const body = h('div.stack', h('p.small', { text: 'Loading…' }));
  const draw = (words) => {
    clear(body);
    body.append(h('p.help', { text: 'Words you marked "Spelled right" in the spelling check. Remove one if it was a mistake.' }));
    if (!words.length) body.append(h('p.small', { text: 'None yet.' }));
    body.append(h('ul.words', words.map((w) => h('li', h('span', h('strong', { text: w.word }), h('span.small', { text: ` · ${w.by}, ${w.when}` })),
      h('button.btn.small.ghost', { type: 'button', text: 'Remove', onclick: (e) => busy(e.currentTarget, async () => {
        try { const r = await api('words-remove', { word: w.word }); S.words = new Set(r.words); await load(); } catch (x) { toast(x.message, 'bad'); }
      }) })))));
    const inp = h('input.input', { autocapitalize: 'off', autocorrect: 'off', spellcheck: false, placeholder: 'A word', 'aria-label': 'Teach a word' });
    body.append(h('div.row', inp, h('button.btn', { type: 'button', text: 'Teach it', onclick: (e) => busy(e.currentTarget, async () => {
      try { const r = await api('words-add', { word: inp.value }); S.words = new Set(r.words); await load(); } catch (x) { toast(x.message, 'bad'); }
    }) })));
  };
  const load = () => api('words').then((r) => draw(r.words)).catch((e) => loadFailed(body, e));
  const ready = load();
  return { title: 'Words I taught', body, ready };
}

// ------------------------------------------------------------------ "How do I…?"

/**
 * Each card: steps in her words, a picture per step where there is one, and "Take me there"
 * (`reveal` names the box it opens at). Every step names a button that exists.
 */
export const CARDS = [
  { id: 'phone', title: 'Change the phone number', go: '#/s/business', reveal: 'site.phone', steps: [
    ['On the start screen, tap Business details.', 'phone-1'],
    ['Tap the Main phone number box and type the new number. Under the box it shows how visitors will see it; tap "Test call" to try it.', 'phone-2'],
    ['Tap the orange "Put it live…" button at the bottom, check the list, then tap "Put it live". Every page, button and the Google info update by themselves.', 'tray'],
    ['Then change it yourself in the other places listed under "Also change these yourself" on Business details: Google Business Profile, Facebook, business cards, your email signature. Ask John about the e-sign forms.', null],
  ] },
  { id: 'contract', title: 'Replace a contract from an email', go: '#/s/states', steps: [
    ['In Gmail or Mail on your iPhone, open the email and tap the PDF. Tap Share (the square with the arrow), then "Save to Files", "On My iPhone", Save.', null],
    ['In the editor, tap States & contracts, then the state.', 'states-1'],
    ['Next to "Contract PDF", tap "Replace the PDF". Tap Browse or Recents and pick the PDF you saved.', 'contract-1'],
    ['Check the first page it shows you. If it\'s the blank contract with nobody\'s details, tap "Yes, it\'s blank". Then answer whether the e-sign version changed too.', null],
    ['Tap "Put it live…" at the bottom, then "Put it live". The contract keeps its web address, so old links still work.', 'tray'],
  ] },
  { id: 'state', title: 'Add a state', go: '#/s/states', steps: [
    ['Tap States & contracts, then "Add a state".', 'states-add'],
    ['Pick the state. Its outline is added by itself.', null],
    ['Turn on "Jonathan Nelson is licensed here".', null],
    ['Paste the e-sign link from Adobe and tap Test: you should see the blank agreement. No link yet? Leave it empty and the state shows a "Call to sign" card.', null],
    ['Add the contract PDF if you have one, then put it live.', null],
  ] },
  { id: 'review', title: 'Paste a Google review', go: '#/s/reviews', steps: [
    ['Copy it in Safari, not the Maps app: search Google for Think Smart Public Adjusting, tap Reviews, press and hold the review\'s words, tap Select All, then Copy.', null],
    ['In the editor, tap Reviews, then "Add a review".', 'reviews-1'],
    ['Paste the words into "Their words", type their name (it shows as "Michael R."), and turn on "Real customer, copied word for word". Town, stars and the rest are under "More about the review" and are optional.', null],
    ['Tap "Put it live…" at the bottom. The reviews section appears on Home with the first review.', 'tray'],
  ] },
  { id: 'photo', title: 'Swap the photo at the top of Home', go: '#/photos', steps: [
    ['Tap Photos, then "Top of Home" (it\'s the storm video).', 'photos-1'],
    ['Tap "Use my photo instead" and pick a photo. The rain and lightning drawn over the video turn off with it.', null],
    ['Tap the important part of the photo, check the Phone and Computer frames, and keep "Match the site\'s look" on.', null],
    ['Tap "Use this photo", then tap "Put it live…" at the bottom. "Put the video back" brings the video back any time.', null],
  ] },
  { id: 'word', title: 'Fix a word I saw', go: '#/fix', steps: [
    ['On the start screen, tap "Fix a word I saw".', 'start-fix'],
    ['Pick the page at the top, then tap the words with an orange dotted line.', 'fix-1'],
    ['Change the words, then tap "Put it live…" at the bottom. You can also use "Find words…" on the start screen.', null],
  ] },
  { id: 'notice', title: 'Turn on a storm notice', go: '#/notice', steps: [
    ['On the start screen, tap "Storm / closed notice".', 'start-notice'],
    ['Pick Storm response, Office closed, or "John is out until…". That turns the notice on.', 'notice-1'],
    ['Check the words. Under "When", pick "Until I turn it off", "Today only", "This weekend" or "Pick dates…" (Indiana time).', null],
    ['Tap "Put the notice live now" at the bottom.', null],
  ] },
  { id: 'undo', title: 'Undo a change', go: '#/history', steps: [
    ['On the start screen, tap "Undo a change".', 'start-undo'],
    ['Tap the change, check before and after, and tap "Undo this change". Deleted items have a "Bring back" button.', 'history-1'],
    ['To put everything back to how it was at that moment, tap "Put the whole website back to this moment". Logins and passwords are never touched.', null],
  ] },
  { id: 'leads', title: 'Find a lead from the website', go: '#/leads', steps: [
    ['On the start screen, tap "Last lead received". Or tap More, then Leads.', 'start-lead'],
    ['Every claim, message and chat is here for 90 days (photos 14 days), even if its email never arrived. Tap one to see everything the visitor wrote.', 'leads-1'],
    ['Tap Call to phone them back, or "Email it to me again" to get it in your inbox.', null],
    ['If the start screen says leads didn\'t reach your email, check the addresses under Business details → "Where leads are emailed".', null],
  ] },
  { id: 'password', title: 'Forgot my password', go: null, steps: [
    ['On the sign-in screen, tap "Forgot your password?".', null],
    ['Type your username or email and tap "Email me a code". Type the 6-digit code from the email with a new password; let your iPhone save it.', null],
    ['No email? Ask the other person who edits the website: in More → People they tap "Reset … password" and give you a code. Or use a rescue code from the emergency sheet or your Passwords app.', null],
  ] },
  { id: 'instagram', title: 'Add Instagram', go: '#/s/business', reveal: 'site.social', steps: [
    ['Tap Business details, then "Add a social link" under Social links.', 'business-1'],
    ['Tap Instagram, then paste the link or just type @name.', null],
    ['Tap "Put it live…" at the bottom. It shows as "INSTAGRAM ↗" at the bottom of every page, in the phone menu and on the About page.', null],
  ] },
];

export function helpScreen() {
  return {
    title: 'How do I…?',
    body: h('nav.tiles', CARDS.map((c) => h('a.tile', { href: '#/help/' + c.id }, icon('help'), h('span.tile-text', h('span.tile-title', { text: c.title })), icon('chev')))),
  };
}

export function helpCard(id) {
  const c = CARDS.find((x) => x.id === id);
  if (!c) return null;
  return {
    title: c.title,
    back: '#/help',
    body: h('div.stack',
      h('ol.help-steps', c.steps.map(([t, pic]) => h('li', h('p', { text: t }), pic ? h('img.help-pic', { src: `/edit/app/help/${pic}.jpg`, alt: '', loading: 'lazy', onerror: (e) => e.target.remove() }) : null))),
      c.go ? h('button.btn.primary.big', { type: 'button', text: 'Take me there', onclick: () => { if (c.reveal) S.reveal = c.reveal; go(c.go); } }) : null),
  };
}
