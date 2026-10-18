<?php
declare(strict_types=1);

use ThinkSmartDev\Converter;
use ThinkSmartDev\PageConv;

return function (PageConv $p, Converter $cv): void {
    seo($p, 'home', 'Home page');
    chrome($p);
    // Google's business info is rebuilt whole from the settings
    $ld = $p->one('script[type="application/ld+json"]');
    $p->swap($ld->e0, $ld->s1, '⟦derived.jsonld|json⟧', 'json-ld');

    // Top of Home
    $top = $p->one('section[data-screen-label="Hero — The Storm"]');
    video($p, $top->one('video'), 'home.top.video', 'Top of Home: storm video');
    $p->text($top->one('span:starts("Licensed Public Adjusters")'), 'home.top.kicker', '', [], 'Top of Home: small line above the headline');
    foreach ($top->find('h1 > span > span') as $i => $line) $p->rich($line, "home.top.title.$i", 'headline', [], 'Top of Home: headline, line ' . ($i + 1));
    $p->rich($top->one('p'), 'home.top.text', 'lead-bold', [], 'Top of Home: paragraph');
    $p->textChild($top->one('a[href="Claim.dc.html"]'), 0, 'home.top.claimButton', '', [], 'Top of Home: claim button');
    $p->text($top->one('a:starts("Call ")'), 'home.top.callButton', '', ['phone'], 'Top of Home: call button');

    // Scrolling words band: today's 9 phrases, repeated by the band's own rule
    $ticker = $p->one('div[data-ref="ticker"]');
    $spans = $ticker->find('span[style^="font-family"]');
    for ($i = 0; $i < 9; $i++) {
        $p->text($spans[$i], "home.marquee.phrases.$i.text", 'upper', [], 'Scrolling words band: phrase');
        $cv->put("home.marquee.phrases.$i.on", true, 'Home');
    }
    $p->blockNodes([$ticker], <<<'TPL'
  <div data-ref="ticker" style="display:flex; width:max-content; animation:tsTicker ⟦derived.ticker.seconds⟧s linear infinite; will-change:transform;">
⟦#each derived.ticker.halves as half⟧
    <div style="display:flex; gap:0; align-items:center; white-space:nowrap;">
⟦#each derived.ticker.run as w⟧
      <span style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.3em; color:rgba(235,229,216,.6); padding:0 18px;">⟦w⟧</span><span style="color:#F09A2E;">✦</span>
⟦/each⟧
    </div>
⟦/each⟧
  </div>

TPL);

    // Damage photos (sideways strip)
    $dmg = $p->one('section[id="damage"]');
    $p->text($dmg->one('span:text("THE DAMAGE")'), 'home.damage.kicker', 'upper', [], 'Damage photos (sideways strip): small heading');
    $p->rich($dmg->one('h2'), 'home.damage.title', 'headline', [], 'Damage photos (sideways strip): headline');
    $cards = $dmg->find('article[data-tilt]');
    foreach ($cards as $i => $card) {
        $k = "home.damage.cards.$i";
        $img = $card->one('img');
        $p->attr($img, 'src', "$k.photo", '', [], 'Damage photo card: photo');
        $p->attr($img, 'alt', "$k.photoAlt", '', [], 'Damage photo card: what\'s in this photo');
        $tag = $card->one('span');
        $p->textSub($tag, explode('— ', $tag->textTrim(), 2)[1], "$k.tag", 'upper', [], 'Damage photo card: tag word');
        $p->text($card->one('h3'), "$k.title", '', [], 'Damage photo card: title');
        $p->text($card->one('p'), "$k.text", '', [], 'Damage photo card: short text');
    }
    $p->foldNodes($cards, 'home.damage.cards', <<<'TPL'
      <article data-tilt="1" style="width:min(520px,78vw); flex:none; background:#10141B; border:1px solid rgba(235,229,216,.12); border-radius:4px; overflow:hidden; transform-style:preserve-3d;⟦#if @odd⟧ margin-top:44px;⟦/if⟧">
        <div style="overflow:hidden; aspect-ratio:16/10;"><img data-plx="1" src="⟦x.photo⟧" alt="⟦x.photoAlt⟧" style="width:100%; height:100%; object-fit:cover; display:block; transform:scale(1.12); will-change:transform;"></div>
        <div style="padding:22px 24px 26px; display:flex; flex-direction:column; gap:10px;">
          <span style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.26em; color:#F09A2E;">EXHIBIT ⟦@index|letter⟧ — ⟦x.tag|upper⟧</span>
          <h3 style="margin:0; font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:700; text-transform:uppercase; font-size:34px; line-height:1; color:#EBE5D8;">⟦x.title⟧</h3>
          <p style="margin:0; font-size:15px; line-height:1.6; color:rgba(235,229,216,.62);">⟦x.text⟧</p>
        </div>
      </article>

TPL);
    $end = $dmg->one('article[style*="dashed"]');
    $p->rich($end->one('p'), 'home.damage.endTitle', 'headline', [], 'Damage photos (sideways strip): last card words');
    $p->textSub($end->one('a'), 'EVERYTHING WE COVER', 'home.damage.endLink', 'upper', [], 'Damage photos (sideways strip): last card link');
    $p->text($dmg->one('span:text("SCROLL TO EXAMINE THE EVIDENCE")'), 'home.damage.hint', 'upper', [], 'Damage photos (sideways strip): small line under the strip');

    // What insurers do (after the strip)
    $low = $p->one('section[id="lowball"]');
    $p->text($low->one('span:text("THE LOWBALL")'), 'home.lowball.kicker', 'upper', [], 'What insurers do: small heading');
    $p->rich($low->one('h2'), 'home.lowball.title', 'headline', [], 'What insurers do: headline');
    $p->text($low->one('p[data-rv="160"]'), 'home.lowball.text', '', [], 'What insurers do: paragraph');
    $statBox = $low->one('div[style^="display:flex; flex-direction:column; gap:56px"]');
    $stats = $statBox->find('> div');
    foreach (['underpaid' => 80, 'higher' => 747] as $name => $value) {
        $cv->put("numbers.$name.value", $value, 'Home');
        $cv->put("numbers.$name.plus", $name === 'higher', 'Home');
        $cv->put("numbers.$name.percent", true, 'Home');
    }
    $p->text($stats[0]->one('p'), 'numbers.underpaid.caption', '', [], 'Numbers we quote: claims underpaid or denied (caption)');
    $p->text($stats[1]->one('p'), 'numbers.higher.caption', '', [], 'Numbers we quote: higher settlements (caption)');
    $p->text($statBox->one('p[data-rv="200"]'), 'numbers.source', 'upper', [], 'Numbers we quote: where the numbers come from');
    $p->blockNodes([$statBox], <<<'TPL'
      <div style="display:flex; flex-direction:column; gap:56px;">
⟦#each derived.homeNumbers as n⟧
        <div data-rv="⟦@index|times:120⟧" style="border-top:1px solid rgba(235,229,216,.15); padding-top:28px;">
          <span style="position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap;">⟦#if n.plus⟧+⟦/if⟧⟦n.value⟧⟦#if n.percent⟧%⟦/if⟧</span>
          <div aria-hidden="true" style="display:flex; align-items:baseline; gap:6px;">
⟦#if n.plus⟧
            <span style="font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:700; font-size:clamp(40px,4vw,64px); color:#F09A2E;">+</span>
⟦/if⟧
            <span data-count="⟦n.value⟧" style="font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:800; font-size:clamp(96px,11vw,180px); line-height:.85; color:#EBE5D8;">⟦n.value⟧</span>
⟦#if n.percent⟧
            <span style="font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:700; font-size:clamp(40px,4vw,64px); color:#F09A2E;">%</span>
⟦/if⟧
          </div>
          <p style="margin:14px 0 0; font-size:16px; line-height:1.6; color:rgba(235,229,216,.65); max-width:420px;">⟦n.caption⟧</p>
        </div>
⟦/each⟧
⟦#if derived.homeNumbers⟧
        <p data-rv="200" style="margin:0; font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.08em; line-height:1.8; color:rgba(235,229,216,.52); max-width:440px;">⟦numbers.source|upper⟧</p>
⟦/if⟧
      </div>

TPL);
    $fig = $low->one('figure');
    $img = $fig->one('img');
    $p->attr($img, 'src', 'home.lowball.photo', '', [], 'What insurers do: photo of the letter');
    $p->attr($img, 'alt', 'home.lowball.photoAlt', '', [], 'What insurers do: what\'s in this photo');
    $p->text($fig->one('div[data-ref="stamp"]'), 'home.lowball.stamp', '', [], 'What insurers do: stamp word on the letter');
    $capSpans = $fig->find('figcaption > span');
    $p->swapText($capSpans[0], '⟦derived.damageNextLetter⟧', 'F');
    $p->textSub($capSpans[0], 'THE LETTER', 'home.lowball.photoTag', 'upper', [], 'What insurers do: tag under the photo');
    $p->text($capSpans[1], 'home.lowball.photoCaption', '', [], 'What insurers do: words under the photo');

    // How we work (4 steps)
    $fight = $p->one('section[id="fight"]');
    video($p, $fight->one('video'), 'home.fight.video', 'How we work: background video');
    $p->text($fight->one('span:text("THE FIGHT")'), 'home.fight.kicker', 'upper', [], 'How we work: small heading');
    $p->rich($fight->one('h2'), 'home.fight.title', 'headline', [], 'How we work: headline');
    $p->text($fight->one('p[data-rv="160"]'), 'home.fight.text', '', [], 'How we work: paragraph');
    $steps = $fight->find('ol > li');
    foreach ($steps as $i => $li) {
        $p->text($li->one('h3'), "home.steps.$i.title", '', [], 'How we work: step title');
        $p->text($li->one('p'), "home.steps.$i.text", '', [], 'How we work: step text');
    }
    $p->foldNodes($steps, 'home.steps', <<<'TPL'
      <li data-rv="⟦@index|times:80⟧" style="padding:36px 0; border-top:1px solid rgba(235,229,216,.15); ⟦#if @last⟧border-bottom:1px solid rgba(235,229,216,.15); ⟦/if⟧display:grid; grid-template-columns:88px 1fr; gap:20px;">
        <span style="font-family:'IBM Plex Mono',monospace; font-size:14px; color:#F09A2E; padding-top:8px;">/⟦@n|pad:2⟧</span>
        <div>
          <h3 style="margin:0 0 10px; font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:700; text-transform:uppercase; font-size:clamp(28px,2.6vw,40px); line-height:1; color:#EBE5D8;">⟦x.title⟧</h3>
          <p style="margin:0; font-size:16px; line-height:1.65; color:rgba(235,229,216,.65); max-width:520px;">⟦x.text⟧</p>
        </div>
      </li>

TPL);

    // The big statement (words fill in while scrolling)
    $mani = $p->one('section[data-ref="mani"]');
    $p->text($mani->one('span:text("THE THINK SMART DOCTRINE")'), 'home.statement.kicker', 'upper', [], 'Big statement: small heading');
    $cv->put('home.statement.lines', ['A policy is a promise.', ['We make them ', ['hl' => 'keep it.']]], 'Home');
    $cv->label('home.statement.lines', 'Big statement: two lines (orange words fill in last)', 'rich');
    $p->swapInner($mani->one('p'), '⟦#each derived.statement as line⟧⟦#if !@first⟧<br>⟦/if⟧⟦#each line.words as w⟧<span data-w="⟦#if w.amber⟧amber⟦#else⟧bone⟦/if⟧" style="color:transparent; -webkit-text-stroke:1px rgba(235,229,216,.3); transition:color .35s ease;">⟦w.text⟧</span>⟦/each⟧⟦/each⟧');
    $p->text($mani->one('span:starts("KEEP SCROLLING")'), 'home.statement.hint', 'upper', [], 'Big statement: small line under it');

    // Paid-or-Free section
    $g = $p->one('section[id="guarantee"]');
    seal($p, $g->one('img[src="media/seal.webp"]'));
    $p->text($g->one('span:text("THE GUARANTEE")'), 'home.guarantee.kicker', 'upper', [], 'Paid-or-Free section: small heading');
    $p->rich($g->one('h2'), 'home.guarantee.title', 'headline', [], 'Paid-or-Free section: headline');
    $p->rich($g->one('p'), 'home.guarantee.text', 'strong', [], 'Paid-or-Free section: paragraph');
    $p->textChild($g->one('a[href="Claim.dc.html"]'), 0, 'home.guarantee.button', '', [], 'Paid-or-Free section: button');
    $p->text($g->one('span:starts("CONTINGENCY")'), 'home.guarantee.finePrint', 'upper', [], 'Paid-or-Free section: fine print');

    // Case closed
    $cc = $p->one('section[data-screen-label="Case Closed"]');
    $img = $cc->one('img');
    $p->attr($img, 'src', 'home.closed.photo', '', [], 'Case closed: photo');
    $p->attr($img, 'alt', 'home.closed.photoAlt', '', [], 'Case closed: what\'s in this photo');
    $p->text($cc->one('span:starts("FILE CLOSED")'), 'home.closed.kicker', 'upper', [], 'Case closed: small heading');
    $p->rich($cc->one('h2'), 'home.closed.title', 'headline', [], 'Case closed: headline');
    $p->text($cc->one('p'), 'home.closed.text', '', [], 'Case closed: paragraph');

    // Services on Home
    $sr = $p->one('section[id="services"]');
    $p->text($sr->one('h2'), 'home.services.title', '', [], 'Services on Home: headline');
    $p->textSub($sr->one('a[href="Services.dc.html"]'), 'ALL SERVICES', 'home.services.link', 'upper', [], 'Services on Home: link to all services');
    $svc = $sr->find('a[href^="Services.dc.html#"]');
    foreach ($svc as $i => $a) {
        $k = "services.items.$i";
        $spans = $a->find('span');
        $p->text($spans[1], "$k.name");
        $p->text($spans[2], "$k.summary", '', [], 'Service: short summary on Home');
        $cv->put("$k.highlight", strpos((string)$spans[1]->attr('style'), 'color:#F09A2E') !== false, 'Home');
        $p->swapAttr($a, 'href', (string)$cv->get("$k.id"), "⟦$k.id⟧");
        $p->swapAttr($a->one('img'), 'src', (string)$cv->get("$k.icon"), "⟦$k.icon⟧");
    }
    $p->foldNodes($svc, 'services.items', <<<'TPL'
      <a href="Services.dc.html#⟦x.id⟧" data-rv="⟦@index|times:60⟧" style="position:relative; overflow:hidden; background:#0B0E13; padding:32px 28px 40px; display:flex; flex-direction:column; gap:14px; transition:background .3s ease;" style-hover="background:#10141B;">
        <img src="⟦x.icon⟧" alt="" style="position:absolute; right:-14px; bottom:-16px; width:112px; height:112px; object-fit:contain; opacity:.2; pointer-events:none;">
        <span style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.24em; color:rgba(235,229,216,.58);">S/⟦@n|pad:2⟧</span>
        <span style="font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:700; text-transform:uppercase; font-size:32px; line-height:1; color:⟦#if x.highlight⟧#F09A2E⟦#else⟧#EBE5D8⟦/if⟧;">⟦x.name⟧</span>
        <span style="font-size:14px; line-height:1.6; color:rgba(235,229,216,.65);">⟦x.summary⟧</span>
      </a>

TPL);

    // Questions on Home (3 starred FAQ items, with their short Home versions)
    $fq = $p->one('section[data-screen-label="FAQ Teaser"]');
    $p->rich($fq->one('h2'), 'home.faq.title', 'headline', [], 'Questions on Home: headline');
    $p->textSub($fq->one('a[href="FAQ.dc.html"]'), 'ALL QUESTIONS', 'home.faq.link', 'upper', [], 'Questions on Home: link to all questions');
    $details = $fq->find('details');
    foreach ([0, 2, 3] as $k => $faqIndex) {
        $d = $details[$k];
        $cv->put("faq.items.$faqIndex.home", true, 'Home');
        $p->text($d->find('summary > span')[0], "faq.items.$faqIndex.homeQ", '', [], 'FAQ: short question for Home');
        $p->text($d->one('p'), "faq.items.$faqIndex.homeA", '', [], 'FAQ: short answer for Home');
    }
    $p->blockNodes($details, <<<'TPL'
⟦#each derived.homeFaq as q⟧
      <details style="border-top:1px solid rgba(235,229,216,.15); ⟦#if @last⟧border-bottom:1px solid rgba(235,229,216,.15); ⟦/if⟧padding:6px 0;">
        <summary style="list-style:none; cursor:pointer; display:flex; justify-content:space-between; align-items:center; gap:16px; padding:20px 0;">
          <span style="font-size:clamp(17px,1.4vw,20px); font-weight:600; color:#EBE5D8;">⟦q.q⟧</span>
          <span data-faq-icon="1" aria-hidden="true" style="font-family:'IBM Plex Mono',monospace; font-size:20px; color:#F09A2E; flex:none;">+</span>
        </summary>
        <p style="margin:0; padding:0 0 24px; max-width:560px; font-size:15px; line-height:1.7; color:rgba(235,229,216,.65);">⟦q.a|rich:faq⟧</p>
      </details>
⟦/each⟧

TPL);

    // Bottom of Home: call to action
    $fin = $p->one('section[data-screen-label="Final CTA"]');
    video($p, $fin->one('video'), 'home.final.video', 'Bottom of Home: background video');
    $p->text($fin->one('span:starts("FREE CLAIM REVIEW")'), 'home.final.kicker', 'upper', [], 'Bottom of Home: small heading');
    $p->rich($fin->one('h2'), 'home.final.title', 'headline', [], 'Bottom of Home: headline');
    $p->text($fin->one('a:starts("Call ")'), 'home.final.callButton', '', ['phone'], 'Bottom of Home: call button');
    $p->text($fin->one('a[href="Claim.dc.html"]'), 'home.final.claimButton', '', [], 'Bottom of Home: claim button');
    $p->textSub($fin->one('a[href="Claim.dc.html#photos"]'), 'ALREADY HAVE DAMAGE PHOTOS? SUBMIT THEM', 'home.final.photosLink', 'upper', [], 'Bottom of Home: photos link');

    // the band's loop time follows its length (today: 2 × 36s)
    $at = $p->locate('((this.props.tickerSpeed || 36) * 2)');
    $p->swap($at[0] + strlen('((this.props.tickerSpeed || 36) * '), $at[0] + strlen('((this.props.tickerSpeed || 36) * 2'), '⟦derived.ticker.factor|jsnum⟧', 'ticker factor');
};
