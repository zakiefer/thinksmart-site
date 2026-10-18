<?php
// Renders edit/templates with a content file and compares each result with today's file
// (edit-dev/gate1/files). Prints IDENTICAL or a unified diff per file.
//   php edit-dev/compare.php [content.json] [--quiet]
declare(strict_types=1);
require __DIR__ . '/../edit/lib/Html.php';
require __DIR__ . '/../edit/lib/Encode.php';
require __DIR__ . '/../edit/lib/Rich.php';
require __DIR__ . '/../edit/lib/Template.php';
require __DIR__ . '/../edit/lib/Site.php';
require __DIR__ . '/../edit/lib/Grid.php';
require __DIR__ . '/../edit/lib/Notice.php';
require __DIR__ . '/../edit/lib/Derive.php';
use ThinkSmart\{Derive, Rich, Site, Template};

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a[0] !== '-'));
$contentFile = $args[0] ?? __DIR__ . '/../edit/seed/content.json';
$quiet = in_array('--quiet', $argv, true);
Rich::useStyles(__DIR__ . '/../edit/templates/rich-styles.php');
$content = json_decode((string)file_get_contents($contentFile), true, 512, JSON_THROW_ON_ERROR);
$eng = new Template(__DIR__ . '/../edit/templates', Site::refResolver());
$tmp = sys_get_temp_dir() . '/ts-compare-' . getmypid();
@mkdir($tmp);
$same = 0;
foreach (Site::PAGES as $name => $file) {
    $root = $content + ['page' => $name, 'derived' => Derive::all($content)];
    $got = $eng->render("$name.html", $root);
    $want = (string)file_get_contents(__DIR__ . "/gate1/files/$file");
    if ($got === $want) { $same++; echo "IDENTICAL  $file\n"; continue; }
    file_put_contents("$tmp/a", $want);
    file_put_contents("$tmp/b", $got);
    $diff = shell_exec('diff -u ' . escapeshellarg("$tmp/a") . ' ' . escapeshellarg("$tmp/b") . ' | tail -n +3');
    $hunks = preg_match_all("/^@@/m", (string)$diff);
    echo "CHANGED    $file ($hunks hunks)\n";
    if (!$quiet) echo preg_replace('/^/m', '    ', (string)$diff), "\n";
}
echo "$same of " . count(Site::PAGES) . " identical\n";
