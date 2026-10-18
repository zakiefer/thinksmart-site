<?php
declare(strict_types=1);

use ThinkSmartDev\Converter;
use ThinkSmartDev\PageConv;

return function (PageConv $p, Converter $cv): void {
    links($p);
    $panel = $p->one('div[data-ref="chatPanel"]');
    $head = $panel->find('> div')[0];
    $p->text($head->one('span:starts("Think Smart")'), 'chat.title', '', [], 'Chat: title');
    $p->textChild($head->one('span:starts("GOES STRAIGHT")'), 0, 'chat.subtitle', 'upper', [], 'Chat: line under the title');
    $p->text($panel->one('button:text("Send")'), 'chat.send', '', [], 'Chat: send button');
    $p->text($panel->one('span:text("FREE · NO OBLIGATION")'), 'chat.note', 'upper', [], 'Chat: small line at the bottom');
    $p->text($panel->one('a[href="tel:8127745049"]'), 'chat.urgent', 'upper', ['phone'], 'Chat: urgent link at the bottom');

    $p->js("\"Tell us what happened to your property and how to reach you. This isn't a live chat — your message goes straight to a licensed adjuster, who will call or write back.\"", 'chat.intro', [], 'Chat: first message');
    foreach ($p->locate("['8127745049', '8128501050']") as $at) {
        $p->swap($at, $at + strlen("['8127745049', '8128501050']"), '[⟦site.phone|tel|js⟧, ⟦ref.phone2|tel|js⟧]', 'own phones');
    }
    foreach ($p->locate("lower !== 'thinksmartpublicadjusting@gmail.com'") as $at) {
        $p->swap($at + 10, $at + strlen("lower !== 'thinksmartpublicadjusting@gmail.com'"), '⟦site.email|lower|js⟧', 'own email');
    }
    $p->js("\"We couldn't send that just now — please call 812-774-5049 or email thinksmartpublicadjusting@gmail.com and a licensed adjuster will pick it up.\"", 'chat.fail', ['phone', 'email'], 'Chat: reply if sending fails');
    $p->js("\"Done — your message is with our team. A licensed adjuster will call or write back shortly. Urgent? Call 812-774-5049 and we'll pick up.\"", 'chat.doneWithContact', ['phone'], 'Chat: reply after a message with a phone or email');
    $p->js("\"Done — your message is with our team. We don't see a callback number in it though — drop your number here, or call 812-774-5049 so an adjuster can reach you.\"", 'chat.doneNoContact', ['phone'], 'Chat: reply after a message without a phone or email');
    $p->js("'Passed along — anything else we should know?'", 'chat.followUp', [], 'Chat: reply to later messages');
    $p->js("\"Got it. What's the best phone number (or email) for a licensed adjuster to reach you?\"", 'chat.askContact', [], 'Chat: asks for a phone or email');
    $p->js("\"A callback number or email is all we need — we'll take it from there.\"", 'chat.askAgain', [], 'Chat: asks again');
    $p->js("'YOU'", 'chat.youLabel', [], 'Chat: label over the visitor\'s messages');
    $p->js("'THINK SMART'", 'chat.usLabel', [], 'Chat: label over our messages');
    $p->js("'Close chat'", 'chat.close', [], 'Chat: button words while open', 1);
    $p->js("\"Let's chat\"", 'chat.open', [], 'Chat: button words');
};
