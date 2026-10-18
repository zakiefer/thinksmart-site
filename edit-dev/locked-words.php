<?php
// Lists visible words that are still fixed in the templates (not editable), per page.
declare(strict_types=1);
require __DIR__ . '/../edit/lib/Html.php';
use ThinkSmart\Html;
$dir = $argv[1];
foreach (glob("$dir/templates/*.html") as $f) {
    $src = file_get_contents($f);
    $seen = [];
    foreach (Html::tokenize($src) as $t) {
        if ($t['t'] !== 'text' || $t['in'] !== '') continue;
        $raw = substr($src, $t['s'], $t['e'] - $t['s']);
        // drop markers
        $plain = trim(preg_replace('/⟦[^⟧]*⟧/u', '', Html::decode($raw)));
        if ($plain === '' || preg_match('/^[\s→↗↓✦×+\-—·%\/|]*$/u', $plain)) continue;
        $seen[$plain] = ($seen[$plain] ?? 0) + 1;
    }
    echo basename($f), ': ', implode(' | ', array_map(fn($k, $v) => $v > 1 ? "$k (×$v)" : $k, array_keys($seen), $seen)), "\n";
}
