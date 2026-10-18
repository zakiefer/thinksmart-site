<?php
declare(strict_types=1);

use ThinkSmartDev\Converter;
use ThinkSmartDev\PageConv;

return function (PageConv $p, Converter $cv): void {
    seo($p, 'contact', 'Contact page', ['phone']);
    chrome($p);
    noscriptNote($p);

    $top = $p->one('section[data-screen-label="Contact Hero"]');
    $p->text($top->one('span:starts("CONTACT")'), 'contact.top.kicker', 'upper', [], 'Top of Contact: small line above the headline');
    $p->rich($top->one('h1 > span > span'), 'contact.top.title', 'headline', [], 'Top of Contact: headline');
    $p->text($top->one('p'), 'contact.top.text', '', [], 'Top of Contact: paragraph');

    // Ways to reach us (4 cards)
    $cards = $p->one('section[data-screen-label="Contact Channels"]')->find('a[data-rv]');
    foreach (['call', 'claim', 'photos', 'email'] as $i => $k) {
        $s = $cards[$i]->children();
        $name = ['call' => 'Call card', 'claim' => 'File-a-claim card', 'photos' => 'Photos card', 'email' => 'Email card'][$k];
        $p->text($s[0], "contact.channels.$k.kicker", 'upper', [], "Ways to reach us, $name: small heading");
        $p->text($s[1], "contact.channels.$k.title", '', [], "Ways to reach us, $name: title");
        $p->text($s[2], "contact.channels.$k.text", '', ['callback'], "Ways to reach us, $name: words");
        if ($k === 'email') {
            $p->swapInner($s[3], '⟦ref.email|upper|wbr⟧&nbsp;→');
        } elseif ($k === 'call') {
            $p->swapText($s[3], '⟦site.phone⟧', '812-774-5049');
        } else {
            $p->textSub($s[3], explode(' →', $s[3]->textTrim())[0], "contact.channels.$k.action", 'upper', [], "Ways to reach us, $name: action words");
        }
    }

    // Leave a message
    $form = $p->one('section[data-screen-label="Message Form"]');
    $p->text($form->one('span:text("OR LEAVE A MESSAGE")'), 'contact.form.kicker', 'upper', [], 'Leave a message: small heading');
    $p->rich($form->one('h2'), 'contact.form.title', 'headline', [], 'Leave a message: headline');
    $p->text($form->one('p[data-rv="120"]'), 'contact.form.text', '', [], 'Leave a message: paragraph');
    $rows = $form->find('div[data-rv="160"] > div[style^="display:flex; justify-content:space-between"]');
    foreach ($rows as $i => $row) {
        $k = "contact.rows.$i";
        [$label, $val] = $row->children();
        $p->text($label, "$k.label", 'upper', [], 'Contact details row: label');
        if ($val->tag === 'a' && str_starts_with((string)$val->attr('href'), 'tel:')) {
            $cv->put("$k.type", 'phone', 'Contact');
            $cv->put("$k.which", $val->attr('href') === 'tel:8128501050' ? 'phone2' : 'phone', 'Contact');
            $p->swapText($val, $val->attr('href') === 'tel:8128501050' ? '⟦ref.phone2⟧' : '⟦site.phone⟧');
        } elseif ($val->tag === 'a') {
            $cv->put("$k.type", 'email', 'Contact');
            $p->swapInner($val, '⟦ref.email|wbr⟧');
        } else {
            $cv->put("$k.type", 'text', 'Contact');
            $p->text($val, "$k.text", '', ['adjuster', 'price', 'address'], 'Contact details row: words');
        }
    }
    $p->foldNodes($rows, 'contact.rows', <<<'TPL'
        <div style="display:flex; justify-content:space-between; gap:16px;"><span style="font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.2em; color:rgba(235,229,216,.6);">⟦x.label|upper⟧</span>⟦#if x.type == 'phone'⟧<a href="tel:⟦#if x.which == 'phone2'⟧⟦ref.phone2|tel⟧⟦#else⟧⟦site.phone|tel⟧⟦/if⟧" style="font-size:14px; color:#F09A2E; text-align:right;">⟦#if x.which == 'phone2'⟧⟦ref.phone2⟧⟦#else⟧⟦site.phone⟧⟦/if⟧</a>⟦#else⟧⟦#if x.type == 'email'⟧<a href="mailto:⟦site.email⟧" style="font-size:14px; color:#F09A2E; text-align:right; overflow-wrap:anywhere;">⟦ref.email|wbr⟧</a>⟦#else⟧<span style="font-size:14px; color:rgba(235,229,216,.75); text-align:right;">⟦x.text⟧</span>⟦/if⟧⟦/if⟧</div>

TPL);
    $img = $form->one('figure img');
    $p->attr($img, 'src', 'contact.form.photo', '', [], 'Leave a message: photo');
    $p->attr($img, 'alt', 'contact.form.photoAlt', '', [], 'Leave a message: what\'s in this photo');

    // the message form's own words
    $f = $form->one('form');
    $labels = $f->find('label > span');
    $p->text($labels[0], 'forms.contact.nameLabel', 'upper', [], 'Message form: name label');
    $p->text($labels[1], 'forms.contact.phoneLabel', 'upper', [], 'Message form: phone label');
    $p->text($labels[2], 'forms.contact.messageLabel', 'upper', [], 'Message form: message label');
    $p->attr($f->one('input[name="name"]'), 'placeholder', 'forms.contact.namePlaceholder', '', [], 'Message form: hint in the name box');
    $p->attr($f->one('input[name="phone"]'), 'placeholder', 'forms.contact.phonePlaceholder', '', [], 'Message form: hint in the phone box');
    $p->attr($f->one('textarea'), 'placeholder', 'forms.contact.messagePlaceholder', '', [], 'Message form: hint in the message box');
    $p->text($f->one('span:starts("REPLIES")'), 'forms.contact.replies', 'upper', ['callbackShort'], 'Message form: line beside the button');
    $p->rich($f->one('div[role="alert"]'), 'forms.contact.error', 'links-orange', ['phone', 'email'], 'Message form: message if sending fails');
    $done = $form->one('div[data-ref="msgConfirm"]');
    $p->text($done->one('span'), 'forms.contact.doneTag', 'upper', [], 'Message form, after sending: small heading');
    $p->text($done->one('h3'), 'forms.contact.doneTitle', '', [], 'Message form, after sending: headline');
    $p->rich($done->one('p'), 'forms.contact.doneText', 'links-orange', ['callback', 'phone'], 'Message form, after sending: paragraph');
    $p->text($done->one('button'), 'forms.contact.another', 'upper', [], 'Message form, after sending: "send another" button');
    $p->js("'Sending…'", 'forms.contact.sending', [], 'Message form: button while sending');
    $p->js("'Send message →'", 'forms.contact.send', [], 'Message form: button words');
    $p->js("'Please enter a 10-digit phone number so we can call you.'", 'forms.phoneInvalid', [], 'Forms: message when the phone number is too short');
    $p->js("'Please fill out this field.'", 'forms.required', [], 'Forms: message when a required box is empty');
};
