<?php
// Lists every inline text color with transparency in the templates, with its element and a
// proposed class: body (follows "Easier to read") or locked. Used once to mark the templates.
declare(strict_types=1);
require __DIR__ . '/../edit/lib/Html.php';
require __DIR__ . '/lib/Dom.php';
use ThinkSmart\Html;
$dir = __DIR__ . '/../edit/templates';
$rows = [];
foreach (array_merge(glob("$dir/*.html"), glob("$dir/partials/*.html")) as $f) {
    $src = file_get_contents($f);
    $doc = new ThinkSmartDev\Doc($src);
    $walk = function ($n, $ctx) use (&$walk, &$rows, $f) {
        foreach ($n->children() as $c) {
            $sub = $ctx;
            if (in_array($c->tag, ['header', 'footer', 'button', 'label', 'summary', 'form', 'figcaption'], true)) $sub[] = $c->tag;
            if (($c->attrs['data-ref'] ?? '') === 'mobileMenu') $sub[] = 'menu';
            if (($c->attrs['data-ref'] ?? '') === 'chatPanel') $sub[] = 'chat';
            $st = (string)($c->attrs['style'] ?? '');
            if (preg_match_all('/(?<![-\w])color:\s*rgba\((235,229,216|11,14,19),\s*(\.\d+)\)/', $st, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $mono = strpos($st, 'IBM Plex Mono') !== false;
                    preg_match('/font-size:\s*([^;]+)/', $st, $fs);
                    $text = mb_substr(trim(preg_replace('/\s+/', ' ', $c->text())), 0, 50);
                    $rows[] = [basename($f), $c->tag, $m[1] === '11,14,19' ? 'dark' : 'light', $m[2], $mono ? 'mono' : 'sans', $fs[1] ?? '-', implode('/', $sub), $text];
                }
            }
            $walk($c, $sub);
        }
    };
    $walk($doc->root, []);
}
foreach ($rows as $r) echo implode("\t", $r), "\n";
fwrite(STDERR, count($rows) . " colors\n");
