<?php
// The parts every page shares: page head, top bar, phone menu and the bottom of the page.
declare(strict_types=1);

use ThinkSmartDev\PageConv;

/** "Name on Google" and "Short description" for a page (title, meta, share tags, JS title). */
function seo(PageConv $p, string $k, string $name, array $chips = []): void
{
    $p->text($p->one('title'), "seo.$k.title", '', $chips, "$name: name on Google");
    $p->attr($p->one('meta[name="description"]'), 'content', "seo.$k.description", '', $chips, "$name: short description on Google");
    $p->attr($p->one('meta[property="og:title"]'), 'content', "seo.$k.title", '', $chips);
    $p->attr($p->one('meta[property="og:description"]'), 'content', "seo.$k.description", '', $chips);
    $p->swapAttr($p->one('meta[property="og:image"]'), 'content', 'media/og-cover.jpg', '⟦site.sharePicture⟧');
    $title = $p->one('title')->text();
    $p->js("'" . $title . "'", "seo.$k.title");
}

/** Phone and email links anywhere on a page follow the settings. */
function links(PageConv $p): void
{
    foreach ($p->all('a[href="tel:8127745049"]') as $a) $p->swapAttr($a, 'href', '8127745049', '⟦site.phone|tel⟧');
    foreach ($p->all('a[href="tel:8128501050"]') as $a) $p->swapAttr($a, 'href', '8128501050', '⟦ref.phone2|tel⟧');
    foreach ($p->all('a[href^="mailto:thinksmartpublicadjusting@gmail.com"]') as $a) $p->swapAttr($a, 'href', 'thinksmartpublicadjusting@gmail.com', '⟦site.email⟧');
    foreach ($p->all('a:text("812-774-5049")') as $a) $p->text($a, 'site.phone');
}

/** Top bar, phone menu and bottom of the page (the same on every page). */
function chrome(PageConv $p): void
{
    links($p);
    // phone menu: social links
    $menu = $p->one('div[data-ref="mobileMenu"]');
    $menuSocial = $menu->find('a[target="_blank"]');
    social($p, $menuSocial);
    $p->foldNodes($menuSocial, 'site.social',
        "    <a href=\"⟦s.url⟧\" target=\"_blank\" rel=\"noopener\" style=\"font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.16em; color:rgba(235,229,216,.65);\">⟦s.platform|upper⟧ ↗</a>\n", 's');

    $f = $p->one('footer');
    $p->text($f->one('a:text("File a claim")'), 'chrome.footer.claimLink', '', [], 'Bottom of every page: "File a claim" link');
    $p->text($f->one('a:text("Submit photos")'), 'chrome.footer.photosLink', '', [], 'Bottom of every page: "Submit photos" link');
    $p->text($f->one('a:starts("Call ")'), 'chrome.footer.callLink', '', ['phone'], 'Bottom of every page: call link');
    $p->text($f->one('a:text("Email us")'), 'chrome.footer.emailLink', '', [], 'Bottom of every page: email link');
    $p->text($f->one('p'), 'chrome.footer.promise', '', [], 'Bottom of every page: the promise');
    $p->swapText($f->one('span:starts("376 S ORNAMENT W")'), '⟦ref.addressFooter|upper⟧');
    $p->text($f->one('span:starts("PA JONATHAN NELSON")'), 'chrome.footer.license', 'upper', ['adjuster'], 'Bottom of every page: license line');
    $p->swapText($f->one('span:starts("© ")'), '⟦site.businessName|upper⟧', 'THINK SMART PUBLIC ADJUSTING LLC');
    $footSocial = $f->find('a[target="_blank"]');
    social($p, $footSocial);
    $p->foldNodes($footSocial, 'site.social',
        "<a href=\"⟦s.url⟧\" target=\"_blank\" rel=\"noopener\" style=\"font-family:'IBM Plex Mono',monospace; font-size:12px; letter-spacing:.14em; color:rgba(235,229,216,.65); white-space:nowrap;\" style-hover=\"color:#F09A2E;\">⟦s.platform|upper⟧ ↗</a>", 's', true);
}

/** Social link anchors (today: Facebook) as items of site.social. */
function social(PageConv $p, array $anchors): void
{
    foreach ($anchors as $i => $a) {
        $p->attr($a, 'href', "site.social.$i.url");
        $p->swapText($a, "⟦site.social.$i.platform|upper⟧", 'FACEBOOK');
    }
}

/** The notice shown when JavaScript is off (Contact and Claim). */
function noscriptNote(PageConv $p): void
{
    $n = $p->one('body > noscript > p');
    $p->rich($n, 'forms.noscript', 'noscript', ['phone', 'email'], 'Notice when a visitor\'s browser has JavaScript off');
}

/** A background video: file, still picture, and the still picture used as the CSS backdrop. */
function video(PageConv $p, \ThinkSmartDev\Node $v, string $k, string $where): void
{
    $p->attr($v, 'src', "$k.video", '', [], "$where: video");
    $p->attr($v, 'poster', "$k.poster", '', [], "$where: still picture shown before the video plays");
    $p->swapAttr($v, 'style', (string)$v->attr('poster'), "⟦$k.poster|cssurl⟧");
}

/** The "Paid or Free" seal: shown only while the seal switch is on (whole line). */
function seal(PageConv $p, \ThinkSmartDev\Node $n): void
{
    $line = $p->lineOf($n);
    $p->blockRange($line[0], $line[1], "⟦#if site.seal⟧\n" . substr($p->raw, $line[0], $line[1] - $line[0]) . "⟦/if⟧\n");
}
