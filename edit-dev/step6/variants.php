<?php
// Step 6 content-variant matrix: one thing changed at a time from the seed content, plus one
// "everything at the maximum" combo. Each variant is checked the way the editor checks a
// change (Validate + the build's own Lint), built, and laid out as a servable site folder:
//
//   php edit-dev/step6/variants.php <outDir>
//     <outDir>/content/<variant>.json    the content
//     <outDir>/sites/<variant>/          built pages + links to the repo's media, docs, vendor, support.js
//     <outDir>/variants.json             variant => {about, pages that differ from the seed build}
//
// Test content only (made-up reviews and e-sign links). None of it ships.
declare(strict_types=1);

$out = rtrim($argv[1] ?? '', '/');
if ($out === '') { fwrite(STDERR, "usage: php variants.php <outDir>\n"); exit(2); }
$repo = dirname(__DIR__, 2);
require "$repo/edit/lib/load.php";

use ThinkSmart\{Build, Changes, Files, Paths, Validate};

$schema = json_decode((string)file_get_contents("$repo/edit/schema.json"), true, 512, JSON_THROW_ON_ERROR);
$seed = json_decode((string)file_get_contents("$repo/edit/seed/content.json"), true, 512, JSON_THROW_ON_ERROR);

/** Deterministic "_id" on every list item that has none (the editor adds random ones). */
function withIds(array $c, array $lists): array
{
    $walk = function ($node, string $path) use (&$walk, $lists) {
        if (!is_array($node)) return $node;
        if (($lists[$path] ?? null) === true && array_is_list($node)) {
            foreach ($node as $i => $item) {
                if (!is_array($item) || array_is_list($item)) continue;
                if (empty($item['_id'])) $item['_id'] = 't' . substr(md5($path . $i), 0, 5);
                foreach ($item as $k => $v) if ($k !== '_id') $item[$k] = $walk($v, "{$path}[].$k");
                $node[$i] = $item;
            }
            return $node;
        }
        if (array_is_list($node)) return $node;
        foreach ($node as $k => $v) $node[$k] = $walk($v, $path === '' ? (string)$k : "$path.$k");
        return $node;
    };
    return $walk($c, '');
}

$lists = Changes::listPaths($schema);
$seed = withIds($seed, $lists);

// ------------------------------------------------------------------ the pieces

$esign = fn(string $code) => 'https://na2.documents.adobe.com/public/esignWidget?wid=STEP6TEST' . $code . '*';
/** States after the seed's five (IN OH KY IL TN): long names, and some without an e-sign link ("Call to sign"). */
$extra = [
    ['code' => 'MA', 'licensed' => true],                         // longest name, Call to sign
    ['code' => 'PA', 'licensed' => true, 'esign' => $esign('PA')],
    ['code' => 'NC', 'licensed' => true, 'esign' => $esign('NC')],
    ['code' => 'DC', 'licensed' => true],                         // three words, Call to sign
    ['code' => 'MI', 'licensed' => true, 'esign' => $esign('MI')],
    ['code' => 'WA', 'licensed' => true, 'esign' => $esign('WA')],
    ['code' => 'CT', 'licensed' => true],                         // widest one-word name after MA/PA
];
$states = function (int $n) use ($seed, $extra): array {
    $all = array_merge($seed['states'], $extra);
    $list = array_slice($all, 0, $n);
    foreach ($list as $i => $s) $list[$i] = ['_id' => 's' . ($i + 1)] + array_diff_key($s, ['_id' => 1]);
    return $list;
};

$review = fn(string $id, string $text, string $name, array $more = []) => ['_id' => $id, 'text' => $text, 'name' => $name] + $more + ['real' => true];
$reviews = [
    $review('r1', "Hail tore up our roof and both gutters in April and the insurance adjuster offered us barely enough to patch one side. Jonathan came out the next morning, went over every slope with chalk, photographed the soft metal and the siding we hadn't even noticed, and wrote it all up properly. He handled every phone call with the carrier for eleven weeks. We ended up with a full replacement, new gutters, and the siding on the north wall. I wish we had called him first instead of trying to do it ourselves. Honest, patient, and he explains everything in plain English.", 'Michael Rodriguez', ['town' => 'Evansville', 'state' => 'IN', 'claimType' => 'Hail', 'month' => '2026-04', 'source' => 'Google', 'stars' => 5]),
    $review('r2', 'Quick, honest and on our side the whole way. Would recommend to anyone.', 'Denise'),
    $review('r3', "Our claim was denied twice for 'wear and tear.' Think Smart got an engineer out, pushed back with the policy language, and the carrier reversed the denial in about six weeks. They kept us updated every step and never asked us for a dime up front.", 'Carla Jean Whitaker', ['town' => 'Louisville', 'state' => 'KY', 'claimType' => 'Wind', 'month' => '2026-03', 'source' => 'Facebook', 'stars' => 5]),
    $review('r4', "A pipe burst in the upstairs bathroom while we were away for the holidays and water came through two ceilings and into the kitchen cabinets. The first estimate from the insurance company left out the cabinets, the flooring in the hallway, and the drying equipment we had already paid for. Think Smart went line by line through the estimate, found everything that was missing, and sent a supplement with photos and receipts. It took a while, and there were weeks where nothing seemed to happen, but they followed up every single time. The final payment was almost three times the first offer…", 'Thomas Okafor', ['town' => 'Cincinnati', 'state' => 'OH', 'claimType' => 'Water', 'month' => '2026-01', 'stars' => 4, 'trimmed' => true]),
    $review('r5', "I found them on thinksmartpublicadjusting.com after a tree came down on our garage. They answered on a Sunday, came out Monday, and the insurer paid for the garage, the fence and the tree removal. Fair fee, no surprises.", 'Rob Lindqvist', ['state' => 'IL', 'stars' => 5]),
    $review('r6', "After the tornado warning last May we lost shingles on every side of the house, the satellite dish, two windows, and the vinyl on the back porch. I'm a single mom with three kids and I honestly did not know where to start. The adjuster my insurance sent spent twenty minutes on the roof and offered a repair. Jonathan spent most of an afternoon documenting everything, walked me through what my policy actually covered, and stayed on top of the carrier until they agreed to a full roof and the windows. He even helped me understand the depreciation holdback so I knew what to expect.", 'Ashley Marie Huntington-Brooks', ['town' => 'Jeffersonville', 'state' => 'IN', 'claimType' => 'Wind & hail', 'month' => '2025-06', 'source' => 'Google', 'stars' => 5]),
    $review('r7', 'Fire in our kitchen was a nightmare. They made the claim the easy part. Thank you!!', 'Gloria Mendez', ['month' => '2026-08', 'stars' => 5]),
    $review('r8', "We run a small bakery and a storm took out our walk-in cooler and half the roof over the front of the shop. Between the building and the lost inventory I didn't have the time to fight with anyone. Think Smart handled the commercial claim start to finish, including the business interruption part I didn't even know we had. We reopened in five weeks.", 'Dale Patterson', ['town' => 'Bowling Green', 'state' => 'KY', 'claimType' => 'Commercial', 'source' => 'Nextdoor', 'stars' => 5]),
    $review('r9', "I was skeptical about hiring a public adjuster because I thought the insurance company would treat me worse if I did. The opposite happened. Once Think Smart was involved, the carrier's adjuster came back out, a second inspection was scheduled within a week, and suddenly the items they had refused to look at were on the estimate. They explained the contingency fee before I signed anything and it was exactly what they said it would be. My advice is to call them before you accept any offer, even if it sounds reasonable at first, because ours was missing more than half of what we were owed.", 'Kenneth Albright', ['town' => 'Knoxville', 'state' => 'TN', 'claimType' => 'Hail', 'month' => '2025-11', 'source' => 'Google']),
    $review('r10', 'Our flooded basement claim was underpaid by thousands. One call and they took over. Paid in full within two months.', 'Priya Natarajan', ['state' => 'KY', 'claimType' => 'Flood', 'stars' => 5]),
    $review('r11', "Lightning hit the chimney and fried most of the electronics in the house, then smoke damage on top of that. Our insurer wanted receipts for things we'd owned for fifteen years. Think Smart helped us rebuild the inventory list room by room, priced every item, and argued the smoke cleaning scope until it covered the whole first floor. They were always reachable by phone and text, and they never once made us feel rushed.", 'Samuel and Ruth Ellison', ['town' => 'Santa Claus', 'state' => 'IN', 'claimType' => 'Fire & smoke', 'month' => '2026-02', 'source' => 'Google', 'stars' => 5]),
    $review('r12', "Professional from start to finish. The estimate they wrote was twice as detailed as the one from the insurance company, and in the end the carrier accepted almost all of it. Communication could have been a little faster during the busy season, but the result speaks for itself.", 'Hannah Brooks', ['town' => 'Champaign', 'state' => 'IL', 'claimType' => 'Roof', 'month' => '2026-05', 'source' => 'Facebook', 'stars' => 4]),
];

$phrases = function (array $c, int $on): array {
    foreach ($c['home']['marquee']['phrases'] as $i => $p) $c['home']['marquee']['phrases'][$i]['on'] = $i < $on;
    return $c;
};
$notice = function (array $c, string $kind, bool $on = true): array {
    $c['notice']['on'] = $on;
    $c['notice']['kind'] = $kind;
    $c['notice']['start'] = '';
    $c['notice']['end'] = '';
    return $c;
};

// ------------------------------------------------------------------ the variants

$v = [];
$about = [];
$v['seed'] = $seed; $about['seed'] = 'seed content (5 states, 0 reviews, notice off, 9 phrases, standard brightness, seal on, storm video)';
foreach ([1, 6, 9, 12] as $n) {
    $c = $seed; $c['states'] = $states($n); $v["states-$n"] = $c;
    $about["states-$n"] = "$n states: " . implode(' ', array_map(fn($s) => $s['code'] . (empty($s['esign']) ? '(call)' : ''), $c['states']));
}
$c = $seed; $c['states'] = $states(5);
foreach ($c['states'] as $i => $s) if (in_array($s['code'], ['OH', 'TN'], true)) unset($c['states'][$i]['esign']);
$v['states-5-call'] = $c; $about['states-5-call'] = 'the seed 5 states with OH and TN e-sign links removed ("Call to sign" cards in the 6-across grid)';
$c = $seed; $c['states'] = $states(12);
$c['chrome']['footer']['licenseAuto'] = true; $c['home']['top']['kickerAuto'] = true; $c['contact']['rows'][1]['type'] = 'states';
$v['states-12-auto'] = $c; $about['states-12-auto'] = '12 states with all three "automatic from your states" lines on (footer license, Home top line, Contact LICENSE row)';

$c = $seed; $c['home']['reviews']['items'] = $reviews; $v['reviews-12'] = $c;
$about['reviews-12'] = '12 reviews, ' . min(array_map(fn($r) => \ThinkSmart\Text::length($r["text"]), $reviews)) . '-' . max(array_map(fn($r) => \ThinkSmart\Text::length($r["text"]), $reviews)) . ' characters';
foreach (['storm', 'closed', 'away'] as $k) { $v["notice-$k"] = $notice($seed, $k); $about["notice-$k"] = "notice bar on: $k, no start/end"; }
// the longest words the editor calls "fits" (110 letters) with a call button
$c = $notice($seed, 'storm');
$c['notice']['storm']['text'] = "Storm response: we're taking new hail and wind claims across Indiana and Kentucky — free review, no obligation";
$v['notice-long'] = $c; $about['notice-long'] = 'notice bar on: storm with ' . \ThinkSmart\Text::length($c['notice']['storm']['text']) . ' letters (the editor\'s "fits" limit) and its call button';
$v['phrases-3'] = $phrases($seed, 3); $about['phrases-3'] = '3 scrolling phrases ticked';
$v['phrases-20'] = $phrases($seed, 20); $about['phrases-20'] = '20 scrolling phrases ticked';
foreach (['brighter', 'brightest'] as $b) { $c = $seed; $c['site']['brightness'] = $b; $v["bright-$b"] = $c; $about["bright-$b"] = "brightness: $b"; }
$c = $seed; $c['site']['seal'] = false; $v['seal-off'] = $c; $about['seal-off'] = '"Paid or Free" seal switched off';
$c = $seed; $c['home']['top']['video']['photo'] = 'media/og-cover.jpg'; $v['top-photo'] = $c; $about['top-photo'] = 'top of Home: photo (media/og-cover.jpg) instead of the storm video';

$c = $seed;
$c['states'] = $states(12);
$c['home']['reviews']['items'] = $reviews;
$c = $phrases($c, 20);
$c = $notice($c, 'away');
$c['site']['brightness'] = 'brightest';
$v['max'] = $c; $about['max'] = 'everything at the maximum: 12 states, 12 reviews, 20 phrases, notice on (away, the longest), brightest';

// notice switched off with each kind: must build exactly like the seed (checked below, not browsed)
$offChecks = ['notice-off-closed' => $notice($seed, 'closed', false), 'notice-off-away' => $notice($seed, 'away', false)];

// ------------------------------------------------------------------ check, build, lay out

@mkdir("$out/content", 0777, true);
@mkdir("$out/sites", 0777, true);
$paths = Paths::detect(['private' => "$out/private", 'webroot' => "$out/webroot-unused"]);
$val = new Validate($schema);
$builder = new Build($paths);
$built = [];
$fail = 0;
foreach ($v + $offChecks as $name => $content) {
    $problems = $val->problems($content);
    if ($problems) { $fail++; echo "$name: the editor would refuse this content:\n  - " . implode("\n  - ", array_column($problems, 'msg')) . "\n"; }
    try {
        $res = $builder->build($content);
    } catch (Throwable $e) {
        $fail++;
        echo "$name: NOT BUILT: " . $e->getMessage() . "\n";
        continue;
    }
    foreach ($builder->warnings as $w) echo "$name: lint note: $w\n";
    $files = [];
    foreach ($res['files'] as $file => $m) $files[$file] = (string)file_get_contents($res['dir'] . '/site/' . $file);
    $built[$name] = $files;
    if (isset($offChecks[$name])) continue;
    file_put_contents("$out/content/$name.json", json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    $site = "$out/sites/$name";
    Files::rmTree($site);
    mkdir($site, 0777, true);
    foreach ($files as $file => $text) file_put_contents("$site/$file", $text);
    foreach (['media', 'docs', 'vendor', 'support.js'] as $f) symlink("$repo/$f", "$site/$f");
}
$builder->prune(0);

$index = [];
foreach ($v as $name => $_) {
    if (!isset($built[$name])) continue;
    $differ = [];
    foreach ($built[$name] as $file => $text) if (($built['seed'][$file] ?? null) !== $text) $differ[] = $file;
    $index[$name] = ['about' => $about[$name], 'differs' => $differ];
    printf("%-15s %-80s differs: %s\n", $name, substr($about[$name], 0, 80), $name === 'seed' ? '(baseline)' : (implode(' ', $differ) ?: 'nothing'));
}
foreach ($offChecks as $name => $_) {
    if (!isset($built[$name])) continue;
    $same = $built[$name] === $built['seed'];
    if (!$same) $fail++;
    printf("%-15s notice switched off: %s\n", $name, $same ? 'builds exactly like the seed (nothing emitted)' : 'DIFFERS from the seed build');
}
file_put_contents("$out/variants.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo $fail ? "$fail problem(s)\n" : "all variants valid and built\n";
exit($fail ? 1 : 0);
