<?php
declare(strict_types=1);

use ThinkSmartDev\Converter;
use ThinkSmartDev\PageConv;

return function (PageConv $p, Converter $cv): void {
    links($p);
    $m = $p->one('main');
    $p->text($m->one('span[class="tag"]'), 'notfound.tag', 'upper', [], 'Page not found: small line at the top');
    $p->rich($m->one('h1'), 'notfound.title', 'plain-span', [], 'Page not found: headline');
    $p->text($m->one('p'), 'notfound.text', '', [], 'Page not found: paragraph');
    $p->text($m->one('a[href="/"]'), 'notfound.homeButton', '', [], 'Page not found: home button');
    $p->text($m->one('a[href="/Claim.dc.html"]'), 'notfound.claimButton', '', [], 'Page not found: claim button');
};
