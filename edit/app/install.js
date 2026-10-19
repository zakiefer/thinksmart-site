// The installer's pages (/edit/install): the Print button on the emergency sheet, and no
// double sends (setting it up can take a minute).
for (const b of document.querySelectorAll('[data-print]')) {
  b.addEventListener('click', () => window.print());
}

for (const f of document.querySelectorAll('form[data-once]')) {
  const btn = f.querySelector('button[type="submit"]');
  if (btn) btn.dataset.words = btn.textContent;
  f.addEventListener('submit', (ev) => {
    if (f.dataset.sent === '1') { ev.preventDefault(); return; }
    f.dataset.sent = '1';
    // after the form's data is taken, so the button still counts as pressed
    if (btn) setTimeout(() => { btn.disabled = true; btn.textContent = f.dataset.busy || 'One moment…'; }, 0);
  });
}

// back from the next page: the buttons work again
window.addEventListener('pageshow', (ev) => {
  if (!ev.persisted) return;
  for (const f of document.querySelectorAll('form[data-once]')) {
    f.dataset.sent = '';
    const btn = f.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = false; btn.textContent = btn.dataset.words || btn.textContent; }
  }
});

const code = document.getElementById('code');
if (code && !code.value) code.focus();
