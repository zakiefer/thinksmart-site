<?php
// Test-only content variants that switch each new piece on, for Gate 2 screenshots.
// None of this is real client content and none of it ships.
//   php edit-dev/gate2/variants.php <outDir>   -> <outDir>/<variant>.json
declare(strict_types=1);

$out = $argv[1];
@mkdir($out, 0777, true);
$seed = json_decode((string)file_get_contents(__DIR__ . '/../../edit/seed/content.json'), true, 512, JSON_THROW_ON_ERROR);
$v = [];

$c = $seed; $c['site']['seal'] = false; $v['seal-off'] = $c;

$c = $seed;
$c['notice']['on'] = true;
$c['notice']['kind'] = 'storm';
$c['notice']['start'] = (new DateTimeImmutable('-1 hour', new DateTimeZone('America/Chicago')))->format('Y-m-d\TH:i');
$c['notice']['end'] = (new DateTimeImmutable('+3 days', new DateTimeZone('America/Chicago')))->format('Y-m-d\TH:i');
$v['notice-on'] = $c;

$c = $seed;
$c['home']['reviews']['items'] = [
    ['text' => 'TEST REVIEW ONE. They found damage our carrier missed and handled every call with the insurer. Our roof claim was paid in full.', 'name' => 'Sample Reviewer', 'town' => 'Evansville', 'state' => 'IN', 'claimType' => 'Hail', 'month' => '2026-08', 'source' => 'Google', 'stars' => 5, 'real' => true],
    ['text' => 'TEST REVIEW TWO. Short and sweet.', 'name' => 'Test Person', 'stars' => 4, 'real' => true],
    ['text' => 'TEST REVIEW THREE, a longer quote that was trimmed to fit, to see how a long review wraps in the card and how the small line reads when every optional detail is filled in…', 'name' => 'Example', 'town' => 'Louisville', 'state' => 'KY', 'claimType' => 'Water', 'month' => '2026-05', 'source' => 'Facebook', 'trimmed' => true, 'real' => true],
    ['text' => 'NOT SHOWN: not ticked as a real customer.', 'name' => 'Hidden Case', 'real' => false],
];
$v['reviews'] = $c;

$c = $seed; $c['site']['brightness'] = 'brighter'; $v['brighter'] = $c;
$c = $seed; $c['site']['brightness'] = 'brightest'; $v['brightest'] = $c;

$c = $seed;
foreach (['MA', 'PA', 'MI', 'TX', 'WI', 'NC', 'MO'] as $code) $c['states'][] = ['code' => $code, 'licensed' => true, 'esign' => 'https://na2.documents.adobe.com/public/esignWidget?wid=TEST'];
$c['states'][] = ['code' => 'GA', 'licensed' => true]; // no e-sign link: "Call to sign"
$v['states-12'] = $c;

$c = $seed;
foreach ($c['home']['marquee']['phrases'] as $i => $p) $c['home']['marquee']['phrases'][$i]['on'] = $i < 3;
$v['phrases-3'] = $c;
$c = $seed;
foreach ($c['home']['marquee']['phrases'] as $i => $p) $c['home']['marquee']['phrases'][$i]['on'] = $i < 20;
$v['phrases-20'] = $c;

foreach ($v as $name => $content) file_put_contents("$out/$name.json", json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo implode(' ', array_keys($v)), "\n";
