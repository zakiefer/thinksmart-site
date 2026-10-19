// Sign-in pages: "Show" for passwords, and no double sends.
for (const b of document.querySelectorAll('.show-pw')) {
  b.addEventListener('click', () => {
    const inp = b.parentElement.querySelector('input');
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    b.textContent = show ? 'Hide' : 'Show';
    b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
}
for (const f of document.querySelectorAll('form[data-once]')) {
  f.addEventListener('submit', () => {
    const btn = f.querySelector('button[type="submit"]');
    if (btn) setTimeout(() => { btn.disabled = true; btn.textContent = 'One moment…'; }, 0);
  });
}
const first = document.querySelector('form input:not([type="hidden"]):not([value=""]) ~ input, form input[autocomplete="username"]');
if (first && !first.value) first.focus();
