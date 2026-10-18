<?php
declare(strict_types=1);

use ThinkSmartDev\Converter;
use ThinkSmartDev\PageConv;

return function (PageConv $p, Converter $cv): void {
    seo($p, 'faq', 'FAQ page');
    chrome($p);

    $top = $p->one('section[data-screen-label="FAQ Hero"]');
    $p->text($top->one('span:text("FREQUENTLY ASKED QUESTIONS")'), 'faq.top.kicker', 'upper', [], 'Top of FAQ: small line above the headline');
    foreach ($top->find('h1 > span > span') as $i => $line) $p->rich($line, "faq.top.title.$i", 'headline', [], 'Top of FAQ: headline, line ' . ($i + 1));
    $p->rich($top->one('p'), 'faq.top.text', 'faq-top', [], 'Top of FAQ: paragraph');

    $list = $p->one('section[data-screen-label="FAQ List"]');
    $items = $list->find('details');
    foreach ($items as $i => $d) {
        $p->text($d->find('summary > span > span')[1], "faq.items.$i.q", '', [], 'FAQ: question');
        foreach ($d->find('div > p') as $j => $para) $p->rich($para, "faq.items.$i.a.$j.text", 'faq', ['guaranteeShort'], 'FAQ: answer paragraph');
    }
    $p->foldNodes($items, 'faq.items', <<<'TPL'
    <details data-rv="⟦@index|times:40⟧" style="border-top:1px solid rgba(235,229,216,.15); ⟦#if @last⟧border-bottom:1px solid rgba(235,229,216,.15); ⟦/if⟧padding:8px 0;">
      <summary style="list-style:none; cursor:pointer; display:flex; justify-content:space-between; align-items:baseline; gap:20px; padding:26px 0;">
        <span style="display:flex; gap:20px; align-items:baseline;"><span style="font-family:'IBM Plex Mono',monospace; font-size:13px; color:#F09A2E; flex:none;">Q/⟦@n|pad:2⟧</span><span style="font-size:clamp(18px,1.8vw,24px); font-weight:600; color:#EBE5D8;">⟦x.q⟧</span></span>
        <span data-faq-icon="1" aria-hidden="true" style="font-family:'IBM Plex Mono',monospace; font-size:22px; color:#F09A2E; flex:none;">+</span>
      </summary>
      <div style="padding:0 0 30px 58px; max-width:680px; display:flex; flex-direction:column; gap:12px;">
⟦#each x.a as para⟧
        <p style="margin:0; font-size:16px; line-height:1.75; color:rgba(235,229,216,.68);">⟦para.text|rich:faq⟧</p>
⟦/each⟧
      </div>
    </details>

TPL);

    $cta = $p->one('section[data-screen-label="FAQ CTA"]');
    $p->text($cta->one('h2'), 'faq.cta.title', '', [], 'Orange band at the bottom of FAQ: headline');
    $p->text($cta->one('a:starts("Ask it")'), 'faq.cta.callButton', '', ['phone'], 'Orange band at the bottom of FAQ: call button');
    $p->text($cta->one('a[href="Claim.dc.html"]'), 'faq.cta.claimButton', '', [], 'Orange band at the bottom of FAQ: claim button');
};
