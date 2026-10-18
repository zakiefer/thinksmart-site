<?php
// How marks and chips look in each kind of text spot. {} = the words, {tel} = phone digits,
// {mail} = the email address. A spot can only use the marks its style lists.
return [
    // The "page not found" headline (its page styles color the highlighted words).
    'plain-span' => [
        'hl' => '<span>{}</span>',
    ],
    // Big headlines: "Highlight" makes words orange; line breaks are fixed by the design.
    'headline' => [
        'hl' => '<span style="color:#F09A2E;">{}</span>',
        'br' => '<br>',
    ],
    // Home top paragraph: "Bold" is light and heavy.
    'lead-bold' => [
        'b' => '<strong style="color:#EBE5D8;">{}</strong>',
    ],
    // Paragraph on the orange band: "Bold" keeps the band's dark color.
    'strong' => [
        'b' => '<strong>{}</strong>',
    ],
    // FAQ answers: "Highlight" is orange and bold, "Bold" is light and bold.
    'faq' => [
        'hl' => '<strong style="color:#F09A2E;">{}</strong>',
        'b' => '<strong style="color:#EBE5D8;">{}</strong>',
    ],
    // FAQ top paragraph: underlined orange words that dial the main phone.
    'faq-top' => [
        'call' => '<a href="tel:{tel}" style="color:#F09A2E; border-bottom:1px solid rgba(240,154,46,.5);">{}</a>',
    ],
    // Claim "By phone" card: the second phone as an underlined orange link.
    'by-phone' => [
        'ref:phone2' => '<a href="tel:{tel}" style="color:#F09A2E; text-decoration:underline;">{}</a>',
    ],
    // Messages after a form: phone and email as orange links.
    'links-orange' => [
        'ref:phone' => '<a href="tel:{tel}" style="color:#F09A2E;">{}</a>',
        'ref:email' => '<a href="mailto:{mail}" style="color:#F09A2E;">{}</a>',
    ],
    // Claim confirmation: the main phone as a light underlined link.
    'urgent' => [
        'ref:phone' => '<a href="tel:{tel}" style="color:#EBE5D8; border-bottom:1px solid rgba(235,229,216,.3);">{}</a>',
    ],
    // The notice shown when a visitor's browser has JavaScript off (dark text on orange).
    'noscript' => [
        'ref:phone' => '<a href="tel:{tel}" style="color:#0B0E13;">{}</a>',
        'ref:email' => '<a href="mailto:{mail}" style="color:#0B0E13;">{}</a>',
    ],
];
