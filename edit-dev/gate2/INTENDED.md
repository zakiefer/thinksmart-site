# Gate 2: intended differences

Today's site is commit c5efbf7. The built site is `edit/templates` + `edit/seed/content.json`,
built by `php edit/render.php`. Gate 1 proved the conversion itself loses nothing: the
converter checkpoint rebuilds all 10 files byte for byte (`edit-dev/gate1/REPORT.txt`).
Everything below is a deliberate change on top of that, for you to accept or send back.

Status: **D1–D10 all accepted by Zack on 2026-10-18.** The accepted build's file fingerprints are in
`edit-dev/gate2/ACCEPTED.sha256` (check with `php edit-dev/check-accepted.php`).

## Changes visitors can see in the default build

**D1. One state order everywhere** (data-model decision).
The Home page's name on Google, its short description, its share title/description and the
browser tab title now list the states in the order of the States list (the Claim cards' order):
"IN, OH, KY, IL & TN" / "Indiana, Ohio, Kentucky, Illinois and Tennessee". Today they say
"IN, KY, OH, IL & TN". Adding or reordering a state now updates them.

**D2. Two guarantee lengths** (data-model decision).
The guarantee is stored once, short and long. The short one is used in the FAQ cost answer,
the Home description and the Home "cost" question (all unchanged), and now also starts the
Paid-or-Free paragraph on Home: "If your claim isn't paid, our service is free. No retainers…"
(today: "If your claim doesn't get paid, our work costs you nothing. No retainers…").
The long one is the Services fine print (unchanged).

**D3. Grid fill rules** (no lone card beside an empty grey block, for any number of cards).
- Home "What we take on": 2 columns from 547px, 4 from 1093px. Today 820–1092px shows 3 + a lone 4th.
- Contact "ways to reach us": 2 columns from 612px, 4 from 1223px. Today 917–1222px shows 3 + 1.
- Services "What we cover": 2 columns from 656px, 3 from 983px. Today 1310px+ shows 4 + 2 empty cells.
- Claim state cards: same rules as today (now written by the builder for any count; only the CSS
  comment text changed).

**D10. New state outlines.** All 51 (50 states + DC) are drawn from the US Census Bureau's 2023
cartographic boundaries (`cb_2023_us_state_5m`, public domain), each on its own equal-area
projection, fitted to the same 256px box and filled #F09A2E with the soft edge and light rim of the
originals. Alaska leaves out the far-western Aleutians; Hawaii shows its eight main islands. They
live in `media/states/` and the Claim cards now use them (very faint, 22% opacity). Today's five
`media/state-*.webp` files are untouched; once the built site is live nothing uses them, so they can
be deleted then. Generator: `edit-dev/states/make-outlines.py`.

**D4. Reveal delays capped at 200ms.** FAQ questions 7–10 now fade in after 200ms instead of
240–360ms; Home "How we work" step 4 after 200ms instead of 240ms. Longer lists never wait longer.

## Changes visitors can't see

**D5. Copyright year from the visitor's browser.** "© {{ year }}" with `year` returned by each
page's own values, so both of support.js's render passes keep it. Shows 2026 today, 2027 on
New Year's Day with no rebuild. The year is now its own piece of text, which moves 18–60 pixels of
anti-aliasing in Chromium screenshots (WebKit draws it identically).

**D6. Per-position logic moved out of page scripts.** Home damage cards stagger by `i % 2 === 1`
(was `i === 1 || i === 3`, same for 5 cards). The pinned photo strip keeps the design's 92px
clearance, and when a notice bar shows it clears down to the bar's real bottom edge (read from the
page). Reading the header height alone was tried and rejected: the header measures 94px and wavers
while fonts load, which tipped WebKit at 1280px into shrinking the cards.

**D7. Adjuster title in the photo description.** About photo alt text is built from the name and
title settings: "Jonathan Nelson, President & CEO of Think Smart Public Adjusting" (was "…President
and CEO…"). Screen readers read "&" as "and".

**D8. Leftover quirks dropped.** Services sections' internal `data-screen-label` uses the service
name ("New claims" for "New Claims"); a redundant `order:0` on the Denied photo and an unused
`transform` transition on the Wind / Hail tile are gone.

**D9. Chat box hint is editable.** Its phone font-size rule targets the box (`data-ref="chatInput"`)
instead of matching the hint text.

## New pieces (off or invisible by default; screenshots switch each one on with test content)

- **Reviews section** on Home after "Case closed": absent until the first review that is ticked
  "real customer, word for word". Names shortened ("Michael R."), optional stars, town, claim type,
  month, source, "shortened" note. No average rating and no review markup for Google.
- **Storm / closed notice bar**: three ready-made notices, start/end in Indiana time, shown or hidden
  by the visitor's browser through the page's values; sits under the header (height read from the
  page), pinned sections and anchors clear it, prints at the top. Off: nothing is emitted.
- **"Paid or Free" seal switch**: all 4 seals (Home, Services, Claim intake, Claim confirmation).
- **Easier to read**: Standard (today), Brighter, Brightest. 49 body-text spots in the templates
  (paragraphs, descriptions, captions, form messages, mono fine print) move toward full opacity;
  headings, kickers, labels, tags, links, buttons, the ticker, top bar, phone menu, chat and the page
  bottom stay locked. Dark text on the orange bands has its own curve.
- **"Call to sign" card**: a state without an e-sign link gets the same card, dialing the second phone.
- **State-name sizes**: 34px, except Massachusetts 27px and Pennsylvania 32px (measured: they are the
  only names wider than the narrowest card, 157px at 1372px with 6 across).
- **Scrolling band repeat rule**: each half repeats the ticked phrases until wider than 2560px; the loop
  time grows with the width so the speed stays today's (measured 10.8px per character + 48.7px per
  phrase in both browsers). Today's 9 phrases give today's exact 2 repeats and 72s. 14 starter phrases
  are stored unticked.
- **Automatic from your states**: options for the footer license line, the Home top line and a Contact
  row type. All start on today's own words.

## Noise found (not caused by the change)

Today's Home page in WebKit at 1440px measures its pinned photo strip either 3464px or 3487px
long depending on font timing (1 load in 3). The built page measures 3487px every time. The Gate 2
baseline for that one screenshot was re-captured in its usual 3487px state. Visitors only notice it
as a slightly longer or shorter scroll through the strip.

## Not in this round

- Photo darkening for text-over-photo spots (editor-side, with the photo screen in Step 3).
- Fingerprinted photo names and versioned contract links (they come with uploads in Step 3).
