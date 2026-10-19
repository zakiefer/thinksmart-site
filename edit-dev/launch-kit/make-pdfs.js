// Prints the install guide and John's launch-kit checklist to PDFs (Google Drive shows PDFs,
// not web pages). Run after make-zip.php, on its output folder:
//   node edit-dev/launch-kit/make-pdfs.js edit-dev/launch-kit/out
// Makes guide.pdf (from guide.html + pictures/) and launch-kit-checklist.pdf there.
'use strict';
const fs = require('fs');
const path = require('path');
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');

const out = path.resolve(process.argv[2] || path.join(__dirname, 'out'));
(async () => {
  const browser = await pw.chromium.launch();
  const page = await browser.newPage();
  for (const [html, pdf] of [['guide.html', 'guide.pdf'], ['launch-kit-checklist.html', 'launch-kit-checklist.pdf']]) {
    const src = path.join(out, html);
    if (!fs.existsSync(src)) { console.error(`missing ${src} (run make-zip.php first)`); process.exitCode = 1; continue; }
    await page.goto('file://' + src);
    await page.evaluate(async () => { await document.fonts.ready; await Promise.all([...document.images].map((i) => i.decode().catch(() => {}))); });
    const data = await page.pdf({ format: 'Letter', printBackground: true, preferCSSPageSize: true });
    fs.writeFileSync(path.join(out, pdf), data);
    const pages = (data.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
    console.log(`${pdf}: ${pages} page${pages === 1 ? '' : 's'}, ${Math.round(data.length / 1024)} KB`);
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
