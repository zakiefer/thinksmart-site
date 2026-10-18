<?php
declare(strict_types=1);

use ThinkSmartDev\Converter;
use ThinkSmartDev\PageConv;

return function (PageConv $p, Converter $cv): void {
    seo($p, 'about', 'About page');
    chrome($p);

    // Top of About
    $top = $p->one('section[data-screen-label="About Hero"]');
    $img = $top->one('img');
    $p->attr($img, 'src', 'about.top.photo', '', [], 'Top of About: photo');
    $p->attr($img, 'alt', 'about.top.photoAlt', '', [], 'Top of About: what\'s in this photo');
    $p->text($top->one('span:text("ABOUT THINK SMART")'), 'about.top.kicker', 'upper', [], 'Top of About: small line above the headline');
    foreach ($top->find('h1 > span > span') as $i => $line) $p->rich($line, "about.top.title.$i", 'headline', [], 'Top of About: headline, line ' . ($i + 1));
    $p->text($top->one('p'), 'about.top.text', '', [], 'Top of About: paragraph');

    // What we stand on (3 principles)
    $sec = $p->one('section[data-screen-label="Manifesto"]');
    $p->text($sec->one('span:text("WHAT WE STAND ON")'), 'about.principles.kicker', 'upper', [], 'What we stand on: small heading');
    $items = $sec->find('div[data-rv] > div > h2');
    $rows = [];
    foreach ($items as $i => $h2) {
        $row = $h2->parent->parent;
        $rows[] = $row;
        $p->text($h2, "about.principles.items.$i.title", '', [], 'What we stand on: principle title');
        $p->text($row->one('p'), "about.principles.items.$i.text", '', [], 'What we stand on: principle text');
        $cv->put("about.principles.items.$i.highlight", strpos((string)$h2->attr('style'), 'color:#F09A2E') !== false, 'About');
    }
    $p->foldNodes($rows, 'about.principles.items', <<<'TPL'
      <div data-rv="⟦@index|times:60⟧" style="border-top:1px solid rgba(235,229,216,.15); ⟦#if @last⟧border-bottom:1px solid rgba(235,229,216,.15); ⟦/if⟧padding:44px 0; display:grid; grid-template-columns:88px 1fr; gap:20px; align-items:start;">
        <span style="font-family:'IBM Plex Mono',monospace; font-size:14px; color:#F09A2E; padding-top:14px;">/⟦@n|pad:2⟧</span>
        <div style="display:flex; flex-wrap:wrap; justify-content:space-between; gap:20px; align-items:baseline;">
          <h2 style="margin:0; font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:800; text-transform:uppercase; font-size:clamp(36px,5.4vw,88px); line-height:.95; color:⟦#if x.highlight⟧#F09A2E⟦#else⟧#EBE5D8⟦/if⟧;">⟦x.title⟧</h2>
          <p style="margin:0; max-width:400px; font-size:16px; line-height:1.65; color:rgba(235,229,216,.65);">⟦x.text⟧</p>
        </div>
      </div>

TPL);

    // The adjuster
    $adj = $p->one('section[data-screen-label="The Adjuster"]');
    $photo = $adj->one('figure img');
    $p->attr($photo, 'src', 'about.adjuster.photo', '', [], 'The adjuster: photo');
    $p->attr($photo, 'alt', 'about.adjuster.photoAlt', '', ['adjuster'], 'The adjuster: what\'s in this photo');
    $caps = $adj->find('figcaption > span');
    $p->swapText($caps[0], '⟦ref.adjuster|upper⟧');
    $p->swapText($caps[1], '⟦ref.adjusterTitle|upper⟧');
    $p->text($adj->one('span:text("THE ADJUSTER IN YOUR CORNER")'), 'about.adjuster.kicker', 'upper', [], 'The adjuster: small heading');
    $p->swapText($adj->one('h2'), '⟦ref.adjuster⟧');
    $p->text($adj->one('span[data-rv="90"]'), 'about.adjuster.role', 'upper', ['adjusterTitle'], 'The adjuster: title line under the name');
    $bio = $adj->find('p[data-rv]');
    foreach ($bio as $i => $para) $p->text($para, "about.bio.$i.text", '', [], 'The adjuster: story paragraph');
    $p->foldNodes($bio, 'about.bio', <<<'TPL'
      <p data-rv="⟦@index|pick:120,160,180⟧" style="margin:0 0 ⟦@index|pick:18,28⟧px; font-size:17px; line-height:1.7; color:rgba(235,229,216,.72); max-width:520px; text-wrap:pretty;">⟦x.text⟧</p>

TPL);
    $tagBox = $adj->one('div[data-rv="200"]');
    $tags = $tagBox->find('span');
    foreach ($tags as $i => $t) $p->text($t, "about.tags.$i.text", 'upper', [], 'The adjuster: credential tag');
    $p->foldNodes($tags, 'about.tags', <<<'TPL'
        <span style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.16em; color:rgba(235,229,216,.7); border:1px solid rgba(235,229,216,.2); padding:10px 14px; border-radius:2px;">⟦x.text|upper⟧</span>

TPL);
    $aboutSocial = $tagBox->find('a');
    social($p, $aboutSocial);
    $p->foldNodes($aboutSocial, 'site.social', <<<'TPL'
        <a href="⟦s.url⟧" target="_blank" rel="noopener" style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.16em; color:#F09A2E; border:1px solid rgba(240,154,46,.45); padding:10px 14px; border-radius:2px; transition:border-color .25s ease;" style-hover="border-color:#F09A2E;">⟦s.platform|upper⟧ ↗</a>

TPL, 's');

    // A note on referrals
    $ref = $p->one('section[data-screen-label="Referral Note"]');
    $p->text($ref->one('span:text("A NOTE ON REFERRALS")'), 'about.referral.kicker', 'upper', [], 'A note on referrals: small heading');
    $p->rich($ref->one('p[data-rv="80"]'), 'about.referral.title', 'headline', [], 'A note on referrals: big words');
    $p->text($ref->one('p[data-rv="140"]'), 'about.referral.text', '', [], 'A note on referrals: paragraph');

    // Orange band at the bottom of About
    $cta = $p->one('section[data-screen-label="About CTA"]');
    $p->text($cta->one('h2'), 'about.cta.title', '', [], 'Orange band at the bottom of About: headline');
    $p->textChild($cta->one('a[href="Claim.dc.html"]'), 0, 'about.cta.claimButton', '', [], 'Orange band at the bottom of About: claim button');
};
