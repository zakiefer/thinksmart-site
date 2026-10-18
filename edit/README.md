# Site builder (for developers)

The site's pages are built from templates and one content file. Visitors still get normal
`.dc.html` files rendered by `support.js`; nothing here runs when a visitor loads a page.

```
edit/
  render.php          command-line build (the editor calls the same code)
  schema.json         every editable field: plain label, type, limits, list rules, screens
  seed/content.json   the starting content (today's site)
  templates/          the pages with ⟦…⟧ markers, shared partials, rich-text styles
  lib/                the builder: tokenizer, template engine, encoders, lint, build + swap journal
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
    php edit-dev/check-accepted.php   # built pages vs the accepted build (Gate 2, D1–D10), byte for byte
    php edit-dev/tests/run.php        # encoders, input rules, lint, swap journal, content store
    php edit-dev/make-schema.php      # regenerate schema.json (fails on missing labels/design names)

Gate 2 (screenshots + DOM, Chromium and WebKit): `edit-dev/browser/capture.js`,
`edit-dev/gate2/compare-shots.py`, `edit-dev/gate2/review.py`, list in `edit-dev/gate2/INTENDED.md`.
