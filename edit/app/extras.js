// People (who can edit, reset the other person's password, remove a person, rescue codes),
// "Words I taught", and the "How do I…?" picture cards.
import { S, h, icon, clear, api, toast, sheet, go } from './core.js';
import { kb } from './fields.js';

// ------------------------------------------------------------------ people

export function peopleScreen() {
  const body = h('div.stack', h('p.small', { text: 'Loading…' }));
  const draw = (people) => {
    clear(body);
    body.append(h('p.help', { text: 'Everyone here can change everything. The person with "Change alerts" gets an email when phone numbers, the email, e-sign links, contracts, social links or people change, with an Undo button.' }));
    for (const p of people) {
      const card = h('section.person' + (p.removed ? '.is-hidden' : ''),
        h('div.person-head', icon('people'), h('div', h('strong', { text: p.name + (p.me ? ' (you)' : '') }), h('span.small', { text: `Username: ${p.username} · ${p.email}` }))),
        p.removed ? h('p.callout.warn', { text: 'Removed ' + p.removed + '. Can\'t sign in.' }) : null,
        p.devices.length && !p.removed ? h('p.small', { text: 'Signed in on: ' + p.devices.slice(0, 3).map((d) => `${d.what} (${d.last})`).join(', ') }) : null);
      const acts = h('div.row.wrap');
      if (p.removed) {
        acts.append(h('button.btn.small', { type: 'button', text: 'Let them back in', onclick: async () => draw((await api('people-restore', { id: p.id })).people) }));
      } else {
        acts.append(h('label.check', h('input', { type: 'checkbox', checked: p.alerts, onchange: async (e) => { draw((await api('people-update', { id: p.id, alerts: e.target.checked })).people); toast('Saved.'); } }), ' Change alerts'));
        acts.append(h('button.btn.small', { type: 'button', text: 'Change email', onclick: async () => {
          const inp = h('input.input', { ...kb('email'), value: p.email });
          const ok = await sheet('Email for ' + p.name, h('div.stack', h('p.small', { text: 'Sign-in codes go to this address.' }), inp), [{ label: 'Save', value: true, kind: 'primary' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
          if (!ok) return;
          try { draw((await api('people-update', { id: p.id, email: inp.value })).people); toast('Saved.'); } catch (e) { toast(e.message, 'bad', 5000); }
        } }));
        if (p.me) {
          acts.append(h('button.btn.small', { type: 'button', text: 'Change my password', onclick: () => changePassword(p) }));
          acts.append(h('button.btn.small', { type: 'button', text: 'Make new rescue codes', onclick: () => rescue() }));
        } else {
          acts.append(h('button.btn.small', { type: 'button', text: `Reset ${p.name}'s password`, onclick: async () => {
            try {
              const r = await api('people-reset', { id: p.id });
              await sheet(`Code for ${p.name}`, h('div.stack', h('p.code-big', { text: r.code.slice(0, 3) + ' ' + r.code.slice(3) }),
                h('p', { text: `Give ${p.name} this code (it was also emailed to them). On the sign-in screen they tap "Forgot your password?", then "I have a code", and type it with a new password. It works for ${r.hours} hours.` })), [{ label: 'Done', value: true, kind: 'primary' }]);
            } catch (e) { toast(e.message, 'bad', 5000); }
          } }));
          acts.append(h('button.btn.small.danger', { type: 'button', text: 'Remove ' + p.name, onclick: async () => {
            const ok = await sheet(`Remove ${p.name}?`, `${p.name} is signed out everywhere at once and their rescue codes stop working. You can let them back in within 30 days. If they're leaving, also change the GoDaddy and Gmail passwords.`, [{ label: 'Remove', value: true, kind: 'danger' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
            if (!ok) return;
            try { draw((await api('people-remove', { id: p.id })).people); toast(p.name + ' was removed.'); } catch (e) { toast(e.message, 'bad', 5000); }
          } }));
        }
      }
      card.append(acts);
      body.append(card);
    }
    body.append(h('button.btn.add', { type: 'button', onclick: addPerson }, icon('plus'), 'Add a person'));
  };
  const changePassword = async (p) => {
    const user = h('input', { type: 'text', autocomplete: 'username', value: p.username, hidden: true, readOnly: true });
    const cur = h('input.input', { type: 'password', autocomplete: 'current-password', 'aria-label': 'Current password' });
    const nw = h('input.input', { type: 'password', autocomplete: 'new-password', minLength: 8, 'aria-label': 'New password' });
    const form = h('form.stack', { onsubmit: (e) => e.preventDefault() }, user, h('label.part', h('span', { text: 'Current password' }), cur), h('label.part', h('span', { text: 'New password' }), nw), h('p.small', { text: 'At least 8 characters. Your iPhone can suggest a strong one and save it.' }));
    const ok = await sheet('Change my password', form, [{ label: 'Save', value: true, kind: 'primary' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
    if (!ok) return;
    try { await api('password', { current: cur.value, new: nw.value }); toast('Password changed. Other phones were signed out.'); } catch (e) { toast(e.message, 'bad', 6000); }
  };
  const rescue = async () => {
    const ok = await sheet('Make new rescue codes?', 'Your old rescue codes stop working. You\'ll see two new ones once: write one on the emergency sheet and save the other in your Passwords app.', [{ label: 'Make new codes', value: true, kind: 'primary' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
    if (!ok) return;
    try {
      const r = await api('people-rescue', {});
      await sheet('Your new rescue codes', h('div.stack', r.codes.map((c) => h('p.code-big', { text: c })), h('p', { text: 'Each works once, on the sign-in screen under "Forgot your password?" → "Use a rescue code". They won\'t be shown again.' })), [{ label: 'I\'ve saved them', value: true, kind: 'primary' }]);
    } catch (e) { toast(e.message, 'bad', 5000); }
  };
  const addPerson = async () => {
    const name = h('input.input', { ...kb('name'), 'aria-label': 'Name' });
    const user = h('input.input', { autocapitalize: 'none', autocorrect: 'off', spellcheck: false, 'aria-label': 'Username' });
    const mail = h('input.input', { ...kb('email'), 'aria-label': 'Email' });
    const ok = await sheet('Add a person', h('div.stack', h('label.part', h('span', { text: 'Name' }), name), h('label.part', h('span', { text: 'Username (private, not their email)' }), user), h('label.part', h('span', { text: 'Email' }), mail),
      h('p.small', { text: 'They get an email with a code to choose their own password.' })), [{ label: 'Add', value: true, kind: 'primary' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
    if (!ok) return;
    try {
      const r = await api('people-add', { name: name.value, username: user.value, email: mail.value });
      draw(r.people);
      await sheet('Added', h('div.stack', h('p', { text: `${name.value} was emailed a code. You can also give it to them:` }), h('p.code-big', { text: r.code.slice(0, 3) + ' ' + r.code.slice(3) }), h('p.small', { text: 'On the sign-in screen: "Forgot your password?" → "I have a code". It works for 24 hours.' })), [{ label: 'Done', value: true, kind: 'primary' }]);
    } catch (e) { toast(e.message, 'bad', 6000); }
  };
  api('people').then((r) => draw(r.people)).catch((e) => { clear(body).append(h('p.callout.warn', { text: e.message })); });
  return { title: 'People', body };
}

// ------------------------------------------------------------------ words I taught

export function wordsScreen() {
  const body = h('div.stack', h('p.small', { text: 'Loading…' }));
  const draw = (words) => {
    clear(body);
    body.append(h('p.help', { text: 'Words you marked "Spelled right" in the spelling check. Remove one if it was a mistake.' }));
    if (!words.length) body.append(h('p.small', { text: 'None yet.' }));
    body.append(h('ul.words', words.map((w) => h('li', h('span', h('strong', { text: w.word }), h('span.small', { text: ` · ${w.by}, ${w.when}` })),
      h('button.btn.small.ghost', { type: 'button', text: 'Remove', onclick: async () => { const r = await api('words-remove', { word: w.word }); S.words = new Set(r.words); load(); } })))));
    const inp = h('input.input', { autocapitalize: 'off', autocorrect: 'off', spellcheck: false, placeholder: 'A word', 'aria-label': 'Teach a word' });
    body.append(h('div.row', inp, h('button.btn', { type: 'button', text: 'Teach it', onclick: async () => { try { const r = await api('words-add', { word: inp.value }); S.words = new Set(r.words); load(); } catch (e) { toast(e.message, 'bad'); } } })));
  };
  const load = () => api('words').then((r) => draw(r.words)).catch((e) => { clear(body).append(h('p.callout.warn', { text: e.message })); });
  load();
  return { title: 'Words I taught', body };
}

// ------------------------------------------------------------------ "How do I…?"

/** Each card: steps in her words, a picture per step where there is one, and "Take me there". */
export const CARDS = [
  { id: 'phone', title: 'Change the phone number', go: '#/s/business', steps: [
    ['On the start screen, tap Business details.', 'phone-1'],
    ['Tap the Main phone number box and type the new number. Under the box it shows how visitors will see it; tap "Test call" to try it.', 'phone-2'],
    ['At the bottom, tap "Not live yet", then "Check and put live". Every page, button and the Google info update by themselves.', 'tray'],
    ['Then change it yourself in the places listed under "Also change these yourself": Google Business Profile, Facebook, business cards, your email signature. Ask John about the e-sign forms.', null],
  ] },
  { id: 'contract', title: 'Replace a contract from an email', go: '#/s/states', steps: [
    ['In Gmail or Mail on your iPhone, open the email and tap the PDF. Tap Share (the square with the arrow), then "Save to Files", "On My iPhone", Save.', null],
    ['In the editor, tap States & contracts, then the state.', 'states-1'],
    ['Next to "Contract PDF", tap "Replace or view", then "Replace with a new PDF". Tap Browse or Recents and pick the PDF you saved.', 'contract-1'],
    ['Check the first page it shows you. If it\'s the blank contract with nobody\'s details, tap "Yes, it\'s blank". Then answer whether the e-sign version changed too.', null],
    ['Put it live from "Not live yet". The contract keeps its web address, so old links still work.', 'tray'],
  ] },
  { id: 'state', title: 'Add a state', go: '#/s/states', steps: [
    ['Tap States & contracts, then "Add a state".', 'states-add'],
    ['Pick the state. Its outline is added by itself.', null],
    ['Paste the e-sign link from Adobe and tap Test: you should see the blank agreement. No link yet? Leave it empty and the state shows a "Call to sign" card.', null],
    ['Tick "Jonathan Nelson is licensed here". Add the contract PDF if you have one.', null],
    ['Back on States & contracts, "This will also change…" shows the lines that update by themselves. Then put it live.', null],
  ] },
  { id: 'review', title: 'Paste a Google review', go: '#/s/reviews', steps: [
    ['Copy it in Safari, not the Maps app: search Google for Think Smart Public Adjusting, tap Reviews, press and hold the review\'s words, tap Select All, then Copy.', null],
    ['In the editor, tap Reviews, then "Add a review".', 'reviews-1'],
    ['Paste the words into "Their words", type their name (it shows as "Michael R."), and tick "Real customer, copied word for word". Town, stars and the rest are optional.', null],
    ['Put it live. The reviews section appears on Home with the first review.', 'tray'],
  ] },
  { id: 'photo', title: 'Swap the photo at the top of Home', go: '#/photos', steps: [
    ['Tap Photos, then "Top of Home" (it\'s the storm video).', 'photos-1'],
    ['Tap "Use my photo instead" and pick a photo. The rain and lightning drawn over the video turn off with it.', null],
    ['Tap the important part of the photo, check the Phone and Computer frames, and keep "Match the site\'s look" on.', null],
    ['Tap "Use this photo", then put it live. "Put the video back" brings the video back any time.', null],
  ] },
  { id: 'word', title: 'Fix a word I saw', go: '#/fix', steps: [
    ['On the start screen, tap "Fix a word I saw".', 'start-fix'],
    ['Pick the page at the top, then tap the words you want to change. The editor opens their box.', 'fix-1'],
    ['Change the words, then put it live from "Not live yet". You can also use "Find words…" on the start screen.', null],
  ] },
  { id: 'notice', title: 'Turn on a storm notice', go: '#/notice', steps: [
    ['On the start screen, tap "Storm / closed notice".', 'start-notice'],
    ['Turn "Show a notice" on and pick Storm response, Office closed, or "John is out until…".', 'notice-1'],
    ['Check the words. Leave the dates empty to show it until you turn it off, or set when it starts and ends (Indiana time).', null],
    ['Tap "Put the notice live now". It shows at the top of every page.', null],
  ] },
  { id: 'undo', title: 'Undo a change', go: '#/history', steps: [
    ['On the start screen, tap "Undo a change".', 'start-undo'],
    ['Tap the change, check before and after, and tap "Undo this change". Deleted items have a "Bring back" button.', 'history-1'],
    ['To put everything back to how it was at that moment, tap "Put the whole website back to this moment". Logins and passwords are never touched.', null],
  ] },
  { id: 'password', title: 'Forgot my password', go: null, steps: [
    ['On the sign-in screen, tap "Forgot your password?".', null],
    ['Type your username or email and tap "Email me a code". Type the 6-digit code from the email with a new password; let your iPhone save it.', null],
    ['No email? Ask the other person who edits the website: in More → People they tap "Reset … password" and give you a code. Or use a rescue code from the emergency sheet or your Passwords app.', null],
  ] },
  { id: 'instagram', title: 'Add Instagram', go: '#/s/business', steps: [
    ['Tap Business details, then "Add a social link" under Social links.', 'business-1'],
    ['Pick Instagram, then paste the link or just type @name. Tracking bits at the end of links are removed by themselves.', null],
    ['Put it live. It shows as "INSTAGRAM ↗" at the bottom of every page, in the phone menu and on the About page.', null],
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
      c.go ? h('a.btn.primary.big', { href: c.go, text: 'Take me there' }) : null),
  };
}
