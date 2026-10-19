# Site builder and editor (for developers)

The site's pages are built from templates and one content file. Visitors still get normal
`.dc.html` files rendered by `support.js`; nothing here runs when a visitor loads a page.
The editor at `/edit` changes the content and rebuilds the pages.

```
edit/
  index.php           the editor's front door (sign-in pages, the app, /edit/api/*, /edit/p/*)
  render.php          command-line build (the editor calls the same code)
  schema.json         every editable field: plain label, type, limits, list rules, screens, site order
  seed/content.json   the starting content (today's site)
  templates/          the pages with ⟦…⟧ markers, shared partials, rich-text styles
  lib/                the builder (tokenizer, templates, encoders, lint, build + swap journal)
                      and the editor (Auth, Live, Publisher, Preview, Api, Pages, Uploads, …)
  app/                the editor's screens: plain ES modules, no build step; vendor/pdfjs
                      (PDF.js 5.4.296 legacy build, Apache-2.0); words.json; help/*.jpg
```

## Build

    php edit/render.php                       # content.json -> private side folder, checked
    php edit/render.php --content=FILE        # build another content file
    php edit/render.php --publish --webroot=DIR
    php edit/render.php --recover --webroot=DIR    # finish or undo an interrupted swap
    php edit/render.php --undo --webroot=DIR

The private folder defaults to `thinksmart-private` beside the site folder (or `$TS_PRIVATE_DIR`).
It holds `content.json` (plus `content.json.good`), `builds/<id>/` and `swap-journal.json`.
Core PHP only: no mbstring, iconv, ctype, image or zip extensions.

## Templates

    ⟦path⟧  ⟦path|upper⟧  ⟦path|rich:headline⟧     a value; the encoder is chosen from where it lands
    ⟦#each list as x⟧ … ⟦/each⟧                   items with "hidden": true are skipped;
                                                  @index @n @count @first @last @odd @even
    ⟦#if expr⟧ … ⟦#else⟧ … ⟦/if⟧                  a, !a, a == 'v', a != 'v', && and ||
    ⟦>name⟧                                       templates/partials/name.html
    ⟦ref.phone⟧                                   a setting as visitors read it (Site::REFS)

A block marker alone on its line removes the line. Values are first placed as placeholders, the
page is tokenized, and each value is then encoded for its exact spot: HTML text, attribute, URL
(https/tel/mailto/site files only), `<title>`, JS string literal (`|js`), JSON-LD (`|json`), CSS
numbers. Stored text never contains `{{`/`}}` (support.js would bind it in both passes, entities
don't help) and `=` in text inside `<x-dc>` is written `&#61;` (support.js:365's camelCase regex).

`Lint` checks every built file for the runtime's quirks: camelCase or bare boolean attributes,
`window.__resources`, the Google Fonts link after `<style>` in each `<helmet>`, unknown `{{ }}`
bindings, text the camelCase regex would rewrite, link schemes, Google info JSON.

## Checks for developers (`edit-dev/`, not deployed)

    php edit-dev/convert.php          # Gate 1: today's files -> templates + content, byte for byte
    php edit-dev/compare.php          # built pages vs today's, as diffs (the intended differences)
    php edit-dev/check-accepted.php   # built pages vs the accepted build (Gate 2, D1–D12), byte for byte
    php edit-dev/tests/run.php        # encoders, input rules, lint, swap journal, content store
    php edit-dev/make-schema.php      # regenerate schema.json (fails on missing labels/design names)

Step 6 (Chromium and WebKit; outputs go outside the repo):

    php edit-dev/step6/variants.php OUT                              # content variants (states 1–12, reviews, notice, phrases, seal…)
    node edit-dev/step6/matrix.js OUT chromium,webkit all all all 2  # every page × variant × 7 widths (about 20 minutes)
    node edit-dev/step6/editor-sweep.js http://localhost:8790 /tmp/ts-dev   # every editor screen, phone and desktop

Gate 2 (screenshots + DOM, Chromium and WebKit): `edit-dev/browser/capture.js`,
`edit-dev/gate2/compare-shots.py`, `edit-dev/gate2/review.py`, list in `edit-dev/gate2/INTENDED.md`.

## The editor

Phone-first, no framework, no build step. `app/app.js` loads everything from
`/edit/api/boot` and draws screens from `schema.json` (labels, types, lists, screens).

**Private folder** (`thinksmart-private`, never web-reachable):

    config.json            secret, siteUrl, mail ("mail" or "outbox"), dev
    users.json             people: username, email, password hash, rescue code hashes, devices
    sessions/*.json        one per signed-in browser (named by an HMAC of the cookie); 90 days, renewed on use
    throttle.json codes.json tokens.json   slow-down after wrong tries, 6-digit codes, email links
    content.json(.good)    the live content (list items carry "_id")
    drafts/<person>.json   changes not live yet, as operations against a live version
    history/index.json, history/r<rev>.json.gz   every change that went live (whole content + operations)
    builds/<id>/           side-folder builds; publish.json there lets an interrupted publish finish
    contracts/             every contract version (the live one sits at its permanent docs/ address)
    status/ prefs/ words.json outbox/ editor-errors.log

**Changes** are operations, not whole files (`lib/Changes.php`, mirrored in `app/core.js`):
`set` a spot, or `list` (new order, added items). Items are named by `_id`, so two people
editing at once only clash on the very same spot ("Keep his / Use mine"). The server accepts
only operations on spots the schema knows (`Changes::sanitize`).

**Put it live** (`lib/Publisher.php`): apply the operations to the newest live content, check
(`Validate`), build everything into a side folder, swap it in with the journal, then record
History; the request ignores a dropped connection, and `settle()` at the start of every request
finishes anything left half-done. The app then loads each changed page off-screen through
`/edit/p/live/` with `app/check-hook.js` and puts the change back only if the page engine
reports an error. Undo, Bring back and "Put the whole website back" are new changes built from
History snapshots. John is emailed (with an Undo link) when phone numbers, the email, e-sign
links, contracts or social links change, and on sign-ins from new devices.

**Preview** (`lib/Preview.php`): `/edit/p/<Page>.dc.html` renders her draft with `data-edit`
marks (from the template trace) for "Fix a word I saw"; other files redirect to the live ones;
`send-lead.php` there is a stub, and the preview's security policy blocks any other host.

**Security**: own sessions (HTTP-only, SameSite=Lax cookies on `/edit`), CSRF header on every
API call plus an Origin check, strict CSP (no inline scripts or styles, no framing), https
only, uploads checked and cleaned in plain PHP and saved under server-chosen names
(`media/u/`, with a FilesMatch guard), contracts kept privately until they go live.

**Leads** (`send-lead.php` + `lib/Leads.php`): the public mailer runs without the editor's code. It
saves every lead in `<private>/leads/<YYYY-MM>/<id>.json` (photos in `leads/photos/<case>/`) before
emailing it, so the visitor's "sent" means it's safe; leads are kept 90 days, photos 14. It emails the
addresses in `<private>/settings.json` (a new one takes over only after the link in its test lead is
clicked), skips the business's own numbers using `<private>/filters.json`, and counts today's emails
in `<private>/mail-count.json` together with the editor (lead emails stop at 400 a day, with one
"leads are waiting" notice; sign-in codes keep a reserve up to 480). Each lead email carries an
`X-TS-Lead` header so bounces can be matched when the hosting lets the editor read the noreply@
mailbox (config `"bounce": {"mailbox": "{host:993/imap/ssl}INBOX", "user", "password"}`).

**Health** (`lib/Health.php`): the start screen's status line (last lead, bounced or waiting leads;
certificate and domain dates for John only, checked daily), self-repair from a fingerprinted copy in
`<private>/pristine/` made at install (hourly; never .htaccess, .user.ini, .well-known, cgi-bin, media
or docs), and "Repair the editor" in More. PHP's error logs go to `<private>/php-errors.log`.

**Installer and launch kit** (`lib/Installer.php`, Step 5): `/edit/install` works before anything is set
up and only with the setup code (`edit/install-code.hash` holds its hash). It checks PHP, https, upload
limits, folders and disk; makes the private folder and proves it can't be reached over the web; creates
both logins (each sets a password on their own phone with a code) and rescue codes; sends the test lead
for the lead address; imports the old lead logs; writes config.json; builds and publishes; keeps the
pristine copy for self-repair; prints the emergency sheet; then locks itself (`<private>/install.lock` +
`edit/.installed`). `/edit/install/reinstall` (with the code, within a day of unzipping the kit there
again; John is emailed) keeps all content, logins, settings, history and leads, makes a new secret and
rebuilds. "Download a copy" (`lib/Backup.php`, More) streams words, photos and contracts, never logins,
leads or history. After extracting a newer kit over a running site, run the reinstall right away, or the
hourly self-repair puts the files it knows back.

    php edit-dev/make-zip.php <setup code>               # the launch-kit zip (edit-dev/launch-kit/out/) + filled checklist
    node edit-dev/launch-kit/make-pdfs.js                # guide.html and checklist.html as PDFs
    php edit-dev/launch-kit/try-install.php <dir> <code> # unpacks the kit into <dir>/public_html for a local install
    node edit-dev/launch-kit/try-installer.js …          # clicks through the installer in Chromium and WebKit
    php edit-dev/tests/installer.php                     # installer, zip, backup

## Trying the editor locally

    php edit-dev/editor/dev-setup.php /tmp/ts-dev        # throwaway site + private folder + test logins
    TS_WEBROOT=/tmp/ts-dev/site TS_PRIVATE_DIR=/tmp/ts-dev/private \
      php -S localhost:8790 -t /tmp/ts-dev/site edit-dev/editor/router.php
    open http://localhost:8790/edit/                     # logins: edit-dev/editor/dev-people.json

Mail goes to `/tmp/ts-dev/private/outbox/` in development. Checks:

    php edit-dev/tests/editor.php                        # operations, publish/undo, sign-in, uploads, preview marks
    php edit-dev/tests/leads.php                         # the lead mailer (own little server, outbox), Leads, settings, self-repair
    node edit-dev/editor/flows.js http://localhost:8790 /tmp/ts-dev chromium,webkit   # end-to-end, iPhone size
    node edit-dev/editor/help-pictures.js http://localhost:8790 /tmp/ts-dev           # remakes app/help/*.jpg
    php edit-dev/make-words.php                          # remakes app/words.json (Webster's 2nd + extras + site words)
