<?php
declare(strict_types=1);

use ThinkSmartDev\Converter;
use ThinkSmartDev\PageConv;

return function (PageConv $p, Converter $cv): void {
    seo($p, 'claim', 'File a Claim page', ['callback']);
    chrome($p);
    noscriptNote($p);

    // Top of File a Claim
    $in = $p->one('section[data-screen-label="Claim Intake"]');
    video($p, $in->one('video'), 'claim.top.video', 'Top of File a Claim: background video');
    $p->text($in->one('span:starts("INTAKE")'), 'claim.top.kicker', 'upper', [], 'Top of File a Claim: small line above the headline');
    foreach ($in->find('h1 > span > span') as $i => $line) $p->rich($line, "claim.top.title.$i", 'headline', [], 'Top of File a Claim: headline, line ' . ($i + 1));
    $p->text($in->one('p[style^="margin:0 0 36px"]'), 'claim.top.text', '', [], 'Top of File a Claim: paragraph');
    $steps = $in->find('div[style^="display:grid; grid-template-columns:64px 1fr"]');
    foreach ($steps as $i => $st) {
        $p->text($st->one('strong'), "claim.steps.$i.title", '', ['callback'], 'File a Claim steps: step title');
        $p->text($st->one('div > span'), "claim.steps.$i.text", '', [], 'File a Claim steps: step text');
    }
    $p->foldNodes($steps, 'claim.steps', <<<'TPL'
          <div style="display:grid; grid-template-columns:64px 1fr; gap:14px; padding:20px 0; border-bottom:1px solid rgba(235,229,216,.12);">
            <span style="font-family:'IBM Plex Mono',monospace; font-size:13px; color:#F09A2E;">/⟦@n|pad:2⟧</span>
            <div><strong style="display:block; font-size:15px; color:#EBE5D8; margin-bottom:4px;">⟦x.title⟧</strong><span style="font-size:14px; line-height:1.6; color:rgba(235,229,216,.6);">⟦x.text⟧</span></div>
          </div>

TPL);
    seal($p, $in->find('img[src="media/seal-orange.webp"]')[0]->parent);
    $p->textSub($in->one('a[href="{{ photosHref }}"]'), 'SUBMIT PHOTOS', 'claim.top.photosLink', 'upper', [], 'Top of File a Claim: photos link');

    // The claim form's own words
    $f = $in->one('form');
    $p->text($f->one('span:text("CASE FILE — NEW")'), 'forms.claim.fileTag', 'upper', [], 'Claim form: tag at the top');
    $p->text($f->one('span:text("CONFIDENTIAL")'), 'forms.claim.confidential', 'upper', [], 'Claim form: "confidential" tag');
    $labels = [
        'FULL NAME *' => 'name', 'PHONE *' => 'phone', 'EMAIL' => 'email', 'PROPERTY ADDRESS *' => 'address',
        'DATE OF LOSS' => 'date', 'INSURANCE COMPANY' => 'carrier', 'TELL US WHAT HAPPENED' => 'story',
    ];
    foreach ($labels as $text => $k) $p->text($f->one('label > span:text("' . $text . '")'), "forms.claim.labels.$k", 'upper', [], 'Claim form: label');
    $p->text($f->one('span[id="dmgLbl"]'), 'forms.claim.labels.damage', 'upper', [], 'Claim form: damage choices label');
    $p->text($f->one('span[id="statusLbl"]'), 'forms.claim.labels.status', 'upper', [], 'Claim form: claim status label');
    $p->text($f->one('div[id="photos"] > span:text("DAMAGE PHOTOS")'), 'forms.claim.labels.photos', 'upper', [], 'Claim form: photos label');
    foreach (['name' => 'Jane Homeowner', 'phone' => '', 'email' => '', 'address' => '', 'carrier' => ''] as $k => $_) {
        $p->attr($f->one('input[name="' . $k . '"]'), 'placeholder', "forms.claim.placeholders.$k", '', [], 'Claim form: hint in a box');
    }
    $p->attr($f->one('textarea'), 'placeholder', 'forms.claim.placeholders.story', '', [], 'Claim form: hint in the story box');
    $chips = $f->find('button[data-chip]');
    $choices = array_slice($chips, 0, count($chips) - 1); // "Other" stays last
    foreach ($choices as $i => $b) {
        $p->attr($b, 'data-chip', "claim.damageChoices.$i.name", '', [], 'Claim form damage choices: choice');
        $p->swapText($b, "⟦claim.damageChoices.$i.name|upper⟧");
    }
    $p->foldNodes($choices, 'claim.damageChoices', <<<'TPL'
              <button type="button" data-chip="⟦x.name⟧" style="background:transparent; border:1px solid rgba(235,229,216,.25); color:rgba(235,229,216,.75); font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.12em; padding:11px 16px; border-radius:2px; cursor:pointer; transition:all .2s ease;">⟦x.name|upper⟧</button>

TPL);
    foreach ($f->find('div[role="radiogroup"] > label') as $i => $l) {
        $k = ['notFiled', 'waiting', 'underpaid', 'denied'][$i];
        $p->textChild($l, 0, "forms.claim.status.$k", '', [], 'Claim form: claim status choice');
    }
    $drop = $f->one('div[role="button"]');
    $ds = $drop->children();
    $p->text($ds[0], 'forms.claim.dropTitle', 'upper', [], 'Claim form: photo box title');
    $p->text($ds[1], 'forms.claim.dropText', '', [], 'Claim form: photo box words');
    $more = $f->one('span:starts("MORE THAN 10")');
    $p->textChild($more, 0, 'forms.claim.emailPhotosLead', 'upper', [], 'Claim form: line before "email your photos"');
    $p->textSub($more->one('a'), 'EMAIL YOUR PHOTOS', 'forms.claim.emailPhotosLink', 'upper', [], 'Claim form: "email your photos" link');
    $p->text($f->one('span:starts("FREE REVIEW")'), 'forms.claim.submitNote', 'upper', ['callbackShort'], 'Claim form: line beside the button');
    $p->rich($f->one('div[role="alert"]'), 'forms.claim.error', 'links-orange', ['phone', 'email'], 'Claim form: message if sending fails');

    // after sending
    $done = $in->one('div[data-ref="caseConfirm"]');
    seal($p, $done->one('img[src="media/seal-orange.webp"]')->parent);
    $p->textSub($done->one('span:starts("CASE FILE OPENED")'), 'CASE FILE OPENED', 'forms.claim.doneTag', 'upper', [], 'Claim form, after sending: tag before the case number');
    $p->rich($done->one('h2'), 'forms.claim.doneTitle', 'headline', [], 'Claim form, after sending: headline');
    $p->text($done->one('p'), 'forms.claim.doneText', '', ['callback'], 'Claim form, after sending: paragraph');
    $p->text($done->one('span:text("WHILE YOU WAIT")'), 'forms.claim.whileYouWait', 'upper', [], 'Claim form, after sending: small heading');
    $p->textSub($done->one('a[href="{{ photoMailto }}"]'), 'Email photos of the damage', 'forms.claim.donePhotosLink', '', [], 'Claim form, after sending: photos link');
    $p->rich($done->one('span:starts("Urgent?")'), 'forms.claim.doneUrgent', 'urgent', ['phone'], 'Claim form, after sending: urgent line');
    $p->text($done->one('button'), 'forms.claim.another', 'upper', [], 'Claim form, after sending: "file another" button');

    // Sign for your state
    $sign = $p->one('section[id="sign"]');
    $p->text($sign->one('span:starts("OFFICIAL PAPERWORK")'), 'claim.sign.kicker', 'upper', [], 'Sign for your state: small heading');
    $p->rich($sign->one('h2'), 'claim.sign.title', 'headline', [], 'Sign for your state: headline');
    $p->text($sign->one('p'), 'claim.sign.text', '', [], 'Sign for your state: paragraph');
    $cards = $sign->find('div[data-grid="states"] > a');
    $codes = ['Indiana' => 'IN', 'Ohio' => 'OH', 'Kentucky' => 'KY', 'Illinois' => 'IL', 'Tennessee' => 'TN'];
    foreach ($cards as $i => $a) {
        $spans = $a->find('span');
        $code = $codes[$spans[1]->textTrim()];
        $cv->put("states.$i.code", $code, 'Claim');
        $cv->put("states.$i.licensed", true, 'Claim');
        $p->attr($a, 'href', "states.$i.esign", '', [], 'State: e-sign link');
        $p->swapAttr($a->one('img'), 'src', strtolower($code), "⟦states.$i.code|lower⟧");
        $p->text($spans[0], 'claim.sign.cardTag', 'upper', [], 'Sign for your state: tag on each state card');
        $p->swapText($spans[1], "⟦states.$i.code|statename⟧");
        $p->textSub($spans[2], 'SIGN NOW', 'claim.sign.cardButton', 'upper', [], 'Sign for your state: button words on each card');
    }
    $p->foldNodes($cards, 'states', <<<'TPL'
      <a href="⟦x.esign⟧" target="_blank" rel="noopener" style="position:relative; overflow:hidden; background:#0B0E13; padding:28px 26px 34px; display:flex; flex-direction:column; gap:10px; transition:background .3s ease;" style-hover="background:#10141B;">
        <img src="media/state-⟦x.code|lower⟧.webp" alt="" style="position:absolute; right:-16px; bottom:-20px; width:124px; height:124px; object-fit:contain; opacity:.22; pointer-events:none;">
        <span style="font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:.22em; color:rgba(235,229,216,.58);">⟦claim.sign.cardTag|upper⟧</span>
        <span style="font-family:'Big Shoulders Display',Impact,sans-serif; font-weight:700; text-transform:uppercase; font-size:34px; line-height:1; color:#EBE5D8;">⟦x.code|statename⟧</span>
        <span style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.14em; color:#F09A2E;">⟦claim.sign.cardButton|upper⟧ →</span>
      </a>

TPL);
    $phone = $sign->one('div[data-grid="states"] > div');
    $ps = $phone->find('> span');
    $p->text($ps[0], 'claim.byPhone.tag', 'upper', [], '"By phone" card: tag');
    $p->text($ps[1], 'claim.byPhone.title', '', [], '"By phone" card: title');
    $cv->put('claim.byPhone.otherStates', ['TX', 'OK', 'MI', 'IA'], 'Claim');
    $cv->label('claim.byPhone.otherStates', '"By phone" card: other states it names (yours are left out automatically)', 'states');
    $p->rich($ps[2], 'claim.byPhone.text', 'by-phone', ['otherStates', 'phone2'], '"By phone" card: words');
    $hard = $sign->one('div[style^="border:1px dashed"]');
    $p->text($hard->one('span:text("NEED A HARD COPY?")'), 'claim.hardCopy.title', 'upper', [], '"Need a hard copy?" box: heading');
    $p->text($hard->one('span:starts("In the event")'), 'claim.hardCopy.text', '', ['email', 'address'], '"Need a hard copy?" box: words');
    $pdfs = $hard->find('a[href^="docs/"]');
    $cv->put('states.0.pdf', 'docs/think-smart-agreement-indiana.pdf', 'Claim');
    $cv->put('states.2.pdf', 'docs/think-smart-agreement-kentucky.pdf', 'Claim');
    $p->blockNodes($pdfs, <<<'TPL'
⟦#each derived.pdfStates as s⟧
        <a href="⟦s.pdf⟧" target="_blank" rel="noopener" style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.14em; color:#EBE5D8; border:1px solid rgba(235,229,216,.3); padding:13px 18px; border-radius:2px; transition:border-color .25s ease, color .25s ease;" style-hover="border-color:#F09A2E; color:#F09A2E;">⟦s.name|upper⟧ PDF ↓</a>
⟦/each⟧

TPL);

    // words used by the page script
    $p->js("'Uploading your photos — please keep this page open…'", 'forms.claim.photosSending', [], 'Claim form, after sending: while photos upload');
    $p->js("' received with your case file.'", 'forms.claim.photosSent', [], 'Claim form, after sending: after "3 photos"');
    $p->js('" photos received — please email the rest using the link below."', 'forms.claim.photosPartial', [], 'Claim form, after sending: after "2 of 5"');
    $p->js("\"Your photos didn't upload — please email them using the link below so they reach your adjuster.\"", 'forms.claim.photosFailed', [], 'Claim form, after sending: if photos fail');
    foreach ($p->locate("'mailto:thinksmartpublicadjusting@gmail.com?subject='", 0, null, 2) as $at) {
        $p->swap($at, $at + strlen("'mailto:thinksmartpublicadjusting@gmail.com?subject='"), '⟦site.email|fmt:mailto:%s?subject=|js⟧', 'mailto');
    }
    $p->js("'Sending your case file…'", 'forms.claim.sending', [], 'Claim form: button while sending');
    $p->js("'Open my case file →'", 'forms.claim.send', [], 'Claim form: button words');
    $p->js("'Please enter a 10-digit phone number so we can call you.'", 'forms.phoneInvalid');
    $p->js("'Please fill out this field.'", 'forms.required');
};
