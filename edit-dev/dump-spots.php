<?php
// Lists every candidate spot (visible text runs and content-bearing attributes) in the
// Gate 1 baseline files, with line numbers and the section they sit in.
declare(strict_types=1);
require __DIR__ . '/../edit/lib/Html.php';

use ThinkSmart\Html;

$dir = __DIR__ . '/gate1/files';
$pages = $argv[1] ?? 'Home,About,Services,FAQ,Contact,Claim,ChatWidget';
$attrNames = ['alt', 'aria-label', 'placeholder', 'title', 'content', 'href', 'src', 'poster', 'data-chip', 'value', 'label'];
$total = 0;
foreach (explode(',', $pages) as $page) {
    $file = "$dir/$page.dc.html";
    $src = file_get_contents($file);
    $toks = Html::tokenize($src);
    $stack = [];
    $section = '';
    $count = 0;
    foreach ($toks as $t) {
        $line = substr_count($src, "\n", 0, $t['s']) + 1;
        if ($t['t'] === 'tag') {
            $a = Html::attrs($src, $t);
            if (isset($a['data-screen-label'])) $section = $a['data-screen-label'];
            foreach ($attrNames as $an) {
                if (!isset($a[$an]) || $a[$an] === '') continue;
                if ($an === 'content' && $t['name'] !== 'meta') continue;
                if ($an === 'value' && $t['name'] !== 'input') continue;
                printf("%s:%d [%s] <%s %s=\"%s\">\n", $page, $line, $section, $t['name'], $an, $a[$an]);
                $count++;
            }
            if (!$t['self'] && !in_array($t['name'], ['img', 'input', 'meta', 'link', 'br', 'wbr', 'source'], true)) $stack[] = $t['name'];
        } elseif ($t['t'] === 'end') {
            $k = array_search($t['name'], array_reverse($stack, true), true);
            if ($k !== false) array_splice($stack, $k);
        } elseif ($t['t'] === 'text') {
            $raw = substr($src, $t['s'], $t['e'] - $t['s']);
            if ($t['in'] === 'style') continue;
            if ($t['in'] === 'script') { printf("%s:%d [%s] <script> %d bytes\n", $page, $line, $section, strlen($raw)); continue; }
            if (trim($raw) === '') continue;
            printf("%s:%d [%s] %s: %s\n", $page, $line, $section, implode('>', array_slice($stack, -3)), trim(Html::decode($raw)));
            $count++;
        }
    }
    fprintf(STDERR, "%s: %d candidates\n", $page, $count);
    $total += $count;
}
fprintf(STDERR, "total: %d\n", $total);
