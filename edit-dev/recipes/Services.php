<?php
declare(strict_types=1);

use ThinkSmartDev\Converter;
use ThinkSmartDev\PageConv;

return function (PageConv $p, Converter $cv): void {
    seo($p, 'services', 'Services page');
    chrome($p);

    // Top of Services
    $top = $p->one('section[data-screen-label="Services Hero"]');
    video($p, $top->one('video'), 'services.top.video', 'Top of Services');
    $p->text($top->one('span:starts("SERVICES")'), 'services.top.kicker', 'upper', [], 'Top of Services: small line above the headline');
    foreach ($top->find('h1 > span > span') as $i => $line) $p->rich($line, "services.top.title.$i", 'headline', ['servicesCount'], 'Top of Services: headline, line ' . ($i + 1));
    $p->text($top->one('p'), 'services.top.text', '', [], 'Top of Services: paragraph');

    // The four services
    $names = ['New claims', 'Denied claims', 'Estimates', 'Supplements'];
    $sections = $p->all('section[data-screen-label^="Service — "]');
    foreach ($sections as $i => $sec) {
        $k = "services.items.$i";
        $cv->put("$k.name", $names[$i], 'Services');
        $cv->put("$k._qScreen", substr((string)$sec->attr('data-screen-label'), strlen('Service — ')), 'Services');
        $cv->label("$k.name", 'Service: name', 'text');
        $p->attr($sec, 'id', "$k.id");
        $p->swapAttr($sec, 'data-screen-label', $cv->get("$k._qScreen"), "⟦$k._qScreen⟧");
        $text = $sec->one('> div > div');
        $p->attr($text->one('img'), 'src', "$k.icon");
        $label = $text->one('span[data-rv="0"]');
        $p->swapText($label, "⟦$k.name|upper⟧", strtoupper($names[$i]));
        $p->rich($text->one('h2'), "$k.headline", 'headline', [], 'Service: headline');
        $p->text($text->one('p[data-rv="120"]'), "$k.intro", '', [], 'Service: paragraph');
        foreach ($text->find('ul > li') as $j => $li) $p->textChild($li, 0, "$k.points.$j.text", '', [], 'Service: checklist point');
        $box = $text->find('div[data-rv="200"]');
        $isNumber = fn($b) => count($b->children()) === 2 && $b->children()[0]->textTrim() === '70%';
        $cv->put("$k.bigNumber", (bool)array_filter($box, $isNumber), 'Services');
        foreach ($box as $b) {
            $spans = $b->children();
            if ($isNumber($b)) {
                $cv->put('numbers.overturned.value', 70, 'Services');
                $cv->put('numbers.overturned.plus', false, 'Services');
                $cv->put('numbers.overturned.percent', true, 'Services');
                $p->swapText($spans[0], '⟦numbers.overturned.value⟧%');
                $p->text($spans[1], 'numbers.overturned.caption', '', [], 'Numbers we quote: denied claims overturned (caption)');
            } else {
                foreach ($spans as $j => $tag) {
                    $p->text($tag, "$k.tags.$j.text", 'upper', ['price'], 'Service: price tag');
                    $cv->put("$k.tags.$j.highlight", strpos((string)$tag->attr('style'), 'color:#F09A2E') !== false, 'Services');
                }
            }
        }
        $btn = $text->one('a[data-rv="220"]');
        $cv->put("$k.buttonTo", ['Claim.dc.html' => 'claim', 'Contact.dc.html' => 'contact'][$btn->attr('href')], 'Services');
        $p->textSub($btn, explode(' →', $btn->textTrim())[0], "$k.button", 'upper', [], 'Service: button words');
        $fine = $text->find('p[style^="margin:26px"]');
        if ($fine) $p->text($fine[0], "$k.finePrint", 'upper', ['guaranteeLong'], 'Service: fine print');
        $fig = $sec->one('figure');
        $cv->put("$k._qFigure", strpos((string)$fig->attr('style'), 'order:0;') !== false, 'Services');
        $img = $fig->one('img');
        $p->attr($img, 'src', "$k.photo", '', [], 'Service: photo');
        $p->attr($img, 'alt', "$k.photoAlt", '', [], 'Service: what\'s in this photo');
    }
    $p->foldNodes($sections, 'services.items', <<<'TPL'
<section data-screen-label="Service — ⟦x._qScreen⟧" id="⟦x.id⟧" style="padding:clamp(70px,10vh,130px) clamp(20px,4vw,56px); border-bottom:1px solid rgba(235,229,216,.08);⟦#if @odd⟧ background:#070A0F;⟦/if⟧">
  <div style="max-width:1400px; margin:0 auto; display:grid; grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr)); gap:clamp(32px,5vw,80px); align-items:center;">
⟦#if @odd⟧
    <figure data-rv="120" style="margin:0; overflow:hidden; border:1px solid rgba(235,229,216,.12); border-radius:4px; aspect-ratio:16/11;⟦#if x._qFigure⟧ order:0;⟦/if⟧">
      <img data-plx="1" src="⟦x.photo⟧" alt="⟦x.photoAlt⟧" style="width:100%; height:100%; object-fit:cover; display:block; transform:scale(1.12); will-change:transform;">
    </figure>
⟦/if⟧
    <div>
      <img data-rv="0" src="⟦x.icon⟧" alt="" style="width:54px; height:54px; object-fit:contain; display:block; margin-bottom:18px;">
      <span data-rv="0" style="display:inline-block; font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.3em; color:#F09A2E; margin-bottom:16px;">S/⟦@n|pad:2⟧ — ⟦x.name|upper⟧</span>
      <h2 data-rv="60" style="margin:0 0 20px; font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:800; text-transform:uppercase; font-size:clamp(40px,5vw,80px); line-height:.92; color:#EBE5D8;">⟦x.headline|rich:headline⟧</h2>
      <p data-rv="120" style="margin:0 0 24px; font-size:17px; line-height:1.7; color:rgba(235,229,216,.7); max-width:520px; text-wrap:pretty;">⟦x.intro⟧</p>
      <ul data-rv="180" style="margin:0 0 28px; padding:0; list-style:none; display:flex; flex-direction:column; gap:10px;">
⟦#each x.points as pt⟧
        <li style="display:flex; gap:12px; align-items:baseline; font-size:15px; color:rgba(235,229,216,.7);"><span style="color:#F09A2E; font-family:'IBM Plex Mono',monospace;">→</span>⟦pt.text⟧</li>
⟦/each⟧
      </ul>
⟦#if x.bigNumber⟧
      <div data-rv="200" style="display:flex; align-items:baseline; gap:16px; margin:0 0 28px; border:1px solid rgba(240,154,46,.35); border-radius:2px; padding:18px 22px; width:fit-content;">
        <span style="font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:800; font-size:clamp(40px,4vw,64px); line-height:.85; color:#F09A2E;">⟦#if numbers.overturned.plus⟧+⟦/if⟧⟦numbers.overturned.value⟧⟦#if numbers.overturned.percent⟧%⟦/if⟧</span>
        <span style="font-size:14px; line-height:1.55; color:rgba(235,229,216,.7); max-width:260px;">⟦numbers.overturned.caption⟧</span>
      </div>
⟦/if⟧
⟦#if x.tags⟧
      <div data-rv="200" style="display:flex; flex-wrap:wrap; gap:10px; margin:0 0 28px;">
⟦#each x.tags as t⟧
        <span style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.16em; color:⟦#if t.highlight⟧#F09A2E⟦#else⟧rgba(235,229,216,.7)⟦/if⟧; border:1px solid ⟦#if t.highlight⟧rgba(240,154,46,.45)⟦#else⟧rgba(235,229,216,.2)⟦/if⟧; padding:10px 14px; border-radius:2px;">⟦t.text|upper⟧</span>
⟦/each⟧
      </div>
⟦/if⟧
      <a data-rv="220" href="⟦#if x.buttonTo == 'contact'⟧Contact.dc.html⟦#else⟧Claim.dc.html⟦/if⟧" style="display:inline-flex; align-items:center; gap:10px; font-family:'IBM Plex Mono',monospace; font-size:13px; letter-spacing:.14em; color:#F09A2E;">⟦x.button|upper⟧ →</a>
⟦#if x.finePrint⟧
      <p style="margin:26px 0 0; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.08em; line-height:1.8; color:rgba(235,229,216,.52); max-width:520px;">⟦x.finePrint|upper⟧</p>
⟦/if⟧
    </div>
⟦#if @even⟧
    <figure data-rv="120" style="margin:0; overflow:hidden; border:1px solid rgba(235,229,216,.12); border-radius:4px; aspect-ratio:16/11;⟦#if x._qFigure⟧ order:0;⟦/if⟧">
      <img data-plx="1" src="⟦x.photo⟧" alt="⟦x.photoAlt⟧" style="width:100%; height:100%; object-fit:cover; display:block; transform:scale(1.12); will-change:transform;">
    </figure>
⟦/if⟧
  </div>
</section>


TPL, 'x', false, true);

    // What we cover
    $cov = $p->one('section[id="cover"]');
    $p->text($cov->one('span:text("THE COVERAGE INDEX")'), 'services.coverage.kicker', 'upper', [], 'What we cover: small heading');
    $p->text($cov->one('span:starts("IF IT")'), 'services.coverage.kicker2', 'upper', [], 'What we cover: small line on the right');
    $p->text($cov->one('h2'), 'services.coverage.title', '', [], 'What we cover: headline');
    $grid = $cov->one('div[style^="display:grid"]');
    $cells = $grid->children();
    $tiles = array_slice($cells, 0, count($cells) - 1);
    foreach ($tiles as $i => $tile) {
        $k = "services.coverage.tiles.$i";
        $v = $tile->find('video');
        if ($v) {
            video($p, $v[0], $k, 'What we cover: tile');
        } else {
            $img = $tile->one('img');
            $p->attr($img, 'src', "$k.photo", '', [], 'What we cover: tile photo');
            $p->attr($img, 'alt', "$k.photoAlt", '', [], 'What we cover: what\'s in this photo');
            $cv->put("$k._qTransition", strpos((string)$img->attr('style'), 'transform .6s') !== false, 'Services');
        }
        $p->text($tile->find('div > span')[0], "$k.name", '', [], 'What we cover: tile name');
    }
    $p->foldNodes($tiles, 'services.coverage.tiles', <<<'TPL'
      <div data-rv="⟦@index|times:40⟧" style="position:relative; background:#0B0E13; aspect-ratio:16/10; overflow:hidden;">
⟦#if x.video⟧
        <video poster="⟦x.poster⟧" src="⟦x.video⟧" muted="{{ true }}" loop="{{ true }}" playsinline preload="none" style="background:#0B0E13 url(⟦x.poster|cssurl⟧) center/cover no-repeat; position:absolute; inset:0; width:100%; height:100%; object-fit:cover; opacity:.55;"></video>
⟦#else⟧
        <img src="⟦x.photo⟧" alt="⟦x.photoAlt⟧" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover; opacity:.55; transition:opacity .4s ease⟦#if x._qTransition⟧, transform .6s ease⟦/if⟧;">
⟦/if⟧
        <div style="position:absolute; inset:0; background:linear-gradient(180deg, transparent 30%, rgba(7,10,15,.85) 100%);"></div>
        <div style="position:absolute; left:24px; bottom:20px; right:24px; display:flex; justify-content:space-between; align-items:baseline; gap:12px;">
          <span style="font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:700; text-transform:uppercase; font-size:32px; color:#EBE5D8;">⟦x.name⟧</span>
          <span style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.2em; color:#F09A2E;">C/⟦@n|pad:2⟧</span>
        </div>
      </div>

TPL);
    $com = $cells[count($cells) - 1];
    $p->swapAttr($com, 'data-rv', '200', '⟦services.coverage.tiles|count|times:40⟧');
    $p->text($com->one('p'), 'services.coverage.commercial.text', '', [], 'What we cover: "Commercial" tile words');
    $comSpans = $com->find('div > span');
    $p->text($comSpans[0], 'services.coverage.commercial.name', '', [], 'What we cover: "Commercial" tile name');
    $p->swapText($comSpans[1], 'C/⟦services.coverage.tiles|count|plus:1|pad:2⟧');
    $p->text($cov->one('span:text("ALSO ON THE LIST")'), 'services.coverage.tagsTitle', 'upper', [], 'What we cover: "Also on the list" heading');
    $tags = $cov->find('div[style^="display:flex; flex-wrap:wrap; gap:8px"] > span');
    foreach ($tags as $i => $t) $p->text($t, "services.coverage.tags.$i.text", 'upper', [], 'What we cover: also on the list');
    $p->foldNodes($tags, 'services.coverage.tags', <<<'TPL'
      <span style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.14em; color:rgba(235,229,216,.65); border:1px solid rgba(235,229,216,.18); padding:9px 13px; border-radius:2px;">⟦x.text|upper⟧</span>

TPL);

    // Orange band at the bottom of Services
    $cta = $p->one('section[data-screen-label="Services CTA"]');
    seal($p, $cta->one('img[src="media/seal.webp"]'));
    $p->text($cta->one('h2'), 'services.cta.title', '', [], 'Orange band at the bottom of Services: headline');
    $p->text($cta->one('a:starts("Call ")'), 'services.cta.callButton', '', ['phone'], 'Orange band at the bottom of Services: call button');
    $p->text($cta->one('span:starts("FREE CLAIM REVIEW")'), 'services.cta.note', 'upper', [], 'Orange band at the bottom of Services: small line under the button');
};
