<?php
// Builds the spelling check's word list (edit/app/words.json):
//   Webster's 2nd International (1934, public domain: /usr/share/dict/web2), its proper names,
//   edit-dev/words/extra.txt (modern, insurance and building words, places), and every word
//   already on the website (from the starting content).
//   php edit-dev/make-words.php
declare(strict_types=1);
require __DIR__ . '/../edit/lib/load.php';

$words = [];
$add = function (string $w) use (&$words) {
    $w = strtolower(trim($w));
    if ($w !== '' && preg_match("/^[a-z][a-z'\\/-]*[a-z0-9]$|^[a-z]$/", $w)) $words[$w] = true;
};
foreach (['/usr/share/dict/web2', '/usr/share/dict/propernames'] as $f) {
    if (!is_file($f)) { fwrite(STDERR, "missing $f\n"); exit(1); }
    foreach (file($f) as $l) $add($l);
}
foreach (file(__DIR__ . '/words/extra.txt') as $l) {
    if (str_starts_with(trim($l), '#')) continue;
    foreach (preg_split('/\s+/', $l) as $w) $add($w);
}
// the website's own words
$c = json_decode((string)file_get_contents(__DIR__ . '/../edit/seed/content.json'), true);
$text = [];
array_walk_recursive($c, function ($v) use (&$text) { if (is_string($v)) $text[] = $v; });
preg_match_all("/[A-Za-z][A-Za-z'’]*[A-Za-z]|[A-Za-z]/u", implode(' ', $text), $m);
foreach ($m[0] as $w) $add(str_replace('’', "'", $w));
ksort($words, SORT_STRING);
$list = implode("\n", array_keys($words));
$out = __DIR__ . '/../edit/app/words.json';
file_put_contents($out, json_encode(['n' => count($words), 'words' => $list], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("words: %d (%d KB, %d KB gzipped)\n", count($words), filesize($out) / 1024, strlen(gzencode((string)file_get_contents($out), 9)) / 1024);
