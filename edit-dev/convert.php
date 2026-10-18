<?php
// Converter: today's pages (edit-dev/gate1/files) -> templates + content + labels, then Gate 1.
//
//   php edit-dev/convert.php [outDir]      (default edit-dev/gate1/checkpoint)
//
// Every editable spot is marked by a recipe in edit-dev/recipes/. A spot is a byte range of
// the raw file; nothing is re-serialized, so everything outside the spots stays byte-identical.
// Gate 1 then renders each template with the content and compares it with today's file.
declare(strict_types=1);

require __DIR__ . '/../edit/lib/Html.php';
require __DIR__ . '/../edit/lib/Encode.php';
require __DIR__ . '/../edit/lib/Rich.php';
require __DIR__ . '/../edit/lib/Template.php';
require __DIR__ . '/../edit/lib/Site.php';
require __DIR__ . '/../edit/lib/Grid.php';
require __DIR__ . '/../edit/lib/Notice.php';
require __DIR__ . '/../edit/lib/Derive.php';
require __DIR__ . '/lib/Dom.php';
require __DIR__ . '/lib/Converter.php';

use ThinkSmart\BuildError;
use ThinkSmart\Derive;
use ThinkSmart\Rich;
use ThinkSmart\Site;
use ThinkSmart\Template;
use ThinkSmartDev\Converter;

$out = $argv[1] ?? __DIR__ . '/gate1/checkpoint';
$base = __DIR__ . '/gate1/files';
Rich::useStyles(__DIR__ . '/../edit/templates/rich-styles.php');

$cv = new Converter($base);
// How today's settings read on the site (used to find them inside text).
$cv->chips = [
    'phone' => '812-774-5049',
    'phone2' => '812-850-1050',
    'email' => 'thinksmartpublicadjusting@gmail.com',
    'address' => '376 S Ornament W, Santa Claus, IN 47579',
    'addressFooter' => '376 S Ornament W · Santa Claus, IN 47579',
    'callback' => 'within 24 hours',
    'callbackShort' => 'WITHIN 24H',
    'price' => '$299',
    'adjuster' => 'Jonathan Nelson',
    'adjusterTitle' => 'President & CEO',
    'guaranteeShort' => "if your claim isn't paid, our service is free.",
    'guaranteeLong' => "If your claim isn't paid, our service fee is \$0. Incurred third-party costs (satellite imagery reports, Xactimate estimates) are not included.",
    'otherStates' => 'Texas, Oklahoma, Michigan, Iowa',
    'servicesCount' => 'Four',
];

require __DIR__ . '/recipes/settings.php';
require __DIR__ . '/recipes/chrome.php';
foreach (['FAQ', 'Services', 'Home', 'About', 'Contact', 'Claim', 'ChatWidget', 'NotFound', 'Index', 'Sitemap'] as $page) {
    $file = Site::PAGES[$page];
    $p = $cv->page($page, $file);
    $recipe = __DIR__ . "/recipes/$page.php";
    if (!is_file($recipe)) { fwrite(STDERR, "(no recipe for $page yet)\n"); unset($cv->pages[$page]); continue; }
    try {
        (require $recipe)($p, $cv);
        $p->apply();
        if (function_exists('after_' . $page)) ('after_' . $page)($p, $cv);
    } catch (BuildError | RuntimeException $e) {
        fwrite(STDERR, "CONVERT FAILED in $page: " . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n");
        exit(1);
    }
}

// write templates + content
@mkdir("$out/templates/partials", 0777, true);
foreach ($cv->pages as $name => $p) file_put_contents("$out/templates/$name.html", $p->tpl);
$content = $cv->content = Converter::listify($cv->content);
file_put_contents("$out/content.json", json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents("$out/labels.json", json_encode($cv->labels, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

// Gate 1
$eng = new Template("$out/templates", Site::refResolver());
$fail = 0;
$report = [];
foreach ($cv->pages as $name => $p) {
    try {
        $root = $content + ['page' => $name];
        $root['derived'] = Derive::all($content);
        $got = $eng->render("$name.html", $root);
    } catch (BuildError $e) {
        $fail++;
        $report[] = "FAIL  {$p->file}: " . $e->getMessage();
        continue;
    }
    if ($got === $p->raw) {
        $report[] = sprintf("PASS  %-20s %6d bytes  sha256 %s", $p->file, strlen($got), hash('sha256', $got));
    } else {
        $fail++;
        $report[] = "FAIL  {$p->file}: differs\n" . \ThinkSmartDev\PageConv::firstDiff($got, $p->raw);
    }
}
$markers = 0;
foreach ($cv->pages as $p) $markers += substr_count((string)$p->tpl, Template::OPEN) - substr_count((string)$p->tpl, Template::OPEN . '/');
echo implode("\n", $report), "\n";
printf("Gate 1: %s (%d files, %d markers, %d labeled spots)\n", $fail ? "FAILED ($fail)" : 'PASSED', count($cv->pages), $markers, count($cv->labels));
exit($fail ? 1 : 0);
