<?php
// Editor tests: change operations and conflicts, checks before going live, publish / undo /
// bring back / restore, an interrupted publish, sign-in slow-down, codes, rescue codes,
// one-time links, photo cleaning, the preview's "Fix a word" marks.
//   php edit-dev/tests/editor.php
// Everything runs in a temporary copy of the website; the repo's own files are never written.
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-editor-tests-' . getmypid();
putenv("TS_WEBROOT=$tmp/site");
putenv("TS_PRIVATE_DIR=$tmp/private");
require "$repo/edit/lib/load.php";

use ThinkSmart\{Build, Changes, Clock, Codes, Config, Contracts, Drafts, Explain, Files, Http, Journal, Json, People, Paths, Photos, Preview, Publisher, Schema, Throttle, Tokens, UserError, Validate};

$pass = 0;
$fail = 0;
function ok(bool $c, string $what): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
}

Files::rmTree($tmp);
mkdir("$tmp/site", 0777, true);
mkdir("$tmp/private", 0700, true);
foreach (array_merge(glob("$repo/*.dc.html"), ["$repo/404.html", "$repo/index.html", "$repo/sitemap.xml"]) as $f) copy($f, "$tmp/site/" . basename($f));
@mkdir("$tmp/site/docs");
foreach (glob("$repo/docs/*.pdf") as $f) copy($f, "$tmp/site/docs/" . basename($f));
file_put_contents("$tmp/private/config.json", json_encode(['secret' => str_repeat('k', 64), 'dev' => true, 'mail' => 'outbox', 'siteUrl' => 'https://example.test']));
$paths = Paths::detect();
ok($paths->webroot === "$tmp/site" && $paths->private === "$tmp/private", 'paths come from TS_WEBROOT / TS_PRIVATE_DIR');
$cfg = Config::load($paths);
$people = new People($cfg);
$john = $people->add('John', 'john', 'john@example.test', 'storm-test-2026-alpha', true);
$mun = $people->add('Muneerah', 'muneerah', 'm@example.test', 'hail-test-2026-bravo');
$people->save();
$schema = Schema::load($paths);
$lists = Changes::listPaths($schema);
$outbox = fn() => glob("$tmp/private/outbox/*.json") ?: [];

echo "Change operations\n";
$seed = json_decode((string)file_get_contents("$repo/edit/seed/content.json"), true);
$c0 = Changes::ensureIds($seed, $lists);
$ids = array_column($c0['faq']['items'], '_id');
ok(count(array_unique($ids)) === count($ids) && strlen($ids[0]) > 3, 'every FAQ item gets its own id');
ok(isset($c0['faq']['items'][0]['a'][0]['_id']), 'nested list items get ids too');
ok(Changes::ensureIds($c0, $lists) === $c0, 'ids stay once given');
$c1 = $c0;
$c1['site']['phone'] = '812-774-5050';
$c1['faq']['items'][2]['q'] = 'A new question?';
array_splice($c1['faq']['items'], 4, 1);
$c1['faq']['items'][] = ['_id' => 'new1', 'q' => 'Added?', 'a' => [['_id' => 'na1', 'text' => 'Yes.']]];
$ops = Changes::diff($c0, $c1, $lists);
ok(count($ops) === 3, 'diff: phone, one question, and the list change (' . count($ops) . ')');
[$back] = Changes::apply($c0, $ops);
ok($back === $c1, 'applying the diff gives the same content');
// someone else changed other things meanwhile
$theirs = $c0;
$theirs['site']['email'] = 'other@example.test';
$theirs['faq']['items'][0]['q'] = 'Their edit?';
$theirs['faq']['items'][] = ['_id' => 'zz99', 'q' => 'Theirs added', 'a' => [['_id' => 'zz1', 'text' => 'x']]];
[$merged, $conf] = Changes::apply($theirs, $ops);
ok(!$conf, 'no conflict when different spots changed');
ok($merged['site']['email'] === 'other@example.test' && $merged['site']['phone'] === '812-774-5050', 'both people\'s settings are kept');
ok($merged['faq']['items'][0]['q'] === 'Their edit?' && in_array('zz99', array_column($merged['faq']['items'], '_id'), true) && in_array('new1', array_column($merged['faq']['items'], '_id'), true), 'their edit and both added items are kept');
ok(!in_array($c0['faq']['items'][4]['_id'], array_column($merged['faq']['items'], '_id'), true), 'the item I deleted stays deleted');
$theirs2 = $c0;
$theirs2['site']['phone'] = '812-000-1111';
[$m2, $conf2] = Changes::apply($theirs2, $ops);
ok(count($conf2) === 1 && $conf2[0]['key'] === 'site.phone' && $m2['site']['phone'] === '812-000-1111', 'same spot changed by both: a conflict, theirs kept until she chooses');
[$m3, $conf3] = Changes::apply($theirs2, $ops, ['site.phone' => 'mine']);
ok(!$conf3 && $m3['site']['phone'] === '812-774-5050', '"Use mine" wins');
[$m4] = Changes::apply($theirs2, $ops, ['site.phone' => 'theirs']);
ok($m4['site']['phone'] === '812-000-1111', '"Keep his" keeps theirs');
$gone = $c0;
$qid = $c0['faq']['items'][2]['_id'];
$gone['faq']['items'] = array_values(array_filter($gone['faq']['items'], fn($it) => $it['_id'] !== $qid));
[, $conf5] = Changes::apply($gone, [['op' => 'set', 'path' => ['faq', 'items', ['id' => $qid], 'q'], 'value' => 'x', 'old' => $c0['faq']['items'][2]['q']]]);
ok(($conf5[0]['why'] ?? '') === 'gone', 'a change to an item someone deleted is reported');
$san = Changes::sanitize([
    ['op' => 'set', 'path' => ['site', 'phone'], 'value' => '1', 'old' => '2'],
    ['op' => 'set', 'path' => ['site', 'nonsense'], 'value' => 'x'],
    ['op' => 'set', 'path' => ['site', 'address', 'zip'], 'value' => '47579'],
    ['op' => 'set', 'path' => ['faq', 'items', ['id' => 'abc'], 'hidden'], 'value' => true],
    ['op' => 'set', 'path' => ['faq', '../x'], 'value' => 1],
    ['op' => 'list', 'path' => ['faq', 'items'], 'order' => ['a1'], 'add' => ['a1' => ['_id' => 'a1', 'q' => 'x', 'evil' => 1]], 'old' => []],
    ['op' => 'list', 'path' => ['faq', 'items'], 'order' => ['a2'], 'add' => ['a2' => ['_id' => 'a2', 'q' => 'ok', 'home' => false]], 'old' => []],
], $schema);
ok(count($san) === 4 && $san[1]['path'][2] === 'zip' && $san[3]['order'] === ['a2'], 'only known spots and items with known fields are accepted');
$san2 = Changes::sanitize([
    ['op' => 'set', 'path' => ['faq', 'items'], 'value' => [], 'old' => null],
    ['op' => 'set', 'path' => ['site', 'adjuster', 'evilKey'], 'value' => 'x', 'old' => null],
    ['op' => 'set', 'path' => ['site', 'adjuster'], 'value' => ['name' => 'A', 'evil' => 'x'], 'old' => null],
    ['op' => 'set', 'path' => ['site', 'adjuster'], 'value' => ['name' => 'A', 'title' => 'B'], 'old' => null],
    ['op' => 'set', 'path' => ['home', 'closed', 'photoFocus'], 'value' => ['x' => 10, 'y' => 20, '__proto__' => 1], 'old' => null],
    ['op' => 'set', 'path' => ['home', 'closed', 'photoFocus'], 'value' => ['x' => 10, 'y' => 20], 'old' => null],
], $schema);
ok(count($san2) === 2 && $san2[0]['value'] === ['name' => 'A', 'title' => 'B'] && $san2[1]['value'] === ['x' => 10, 'y' => 20], 'lists change only through list operations; objects keep only their own parts');

$t1 = $c0;
$t1['home']['top']['title'][0] = 'Storms happen.';
$tops = Changes::diff($c0, $t1, $lists);
ok(count($tops) === 1 && $tops[0]['path'] === ['home', 'top', 'title', '0'], 'a headline line is its own spot (home.top.title.0)');
ok(count(Changes::sanitize($tops, $schema)) === 1, 'and the server accepts a change to it');

echo "Checks before going live\n";
$val = new Validate($schema);
ok($val->problems($c0) === [], 'today\'s content has no problems');
$bad = $c0;
$bad['site']['phone'] = '812-774';
$bad['faq']['items'][0]['home'] = !$bad['faq']['items'][0]['home'];
$bad['states'][1]['licensed'] = false;
$bad['home']['reviews']['items'][] = ['_id' => 'rv1', 'text' => 'Great.', 'name' => 'Michael Rodriguez'];
$msgs = array_column($val->problems($bad), 'msg');
ok((bool)array_filter($msgs, fn($m) => str_contains($m, '10 digits')), 'a short phone number is a problem');
ok((bool)array_filter($msgs, fn($m) => str_contains($m, 'exactly 3')), 'Home needs exactly 3 questions');
ok((bool)array_filter($msgs, fn($m) => str_contains($m, 'licensed here')), 'a state needs the licensed tick');
ok((bool)array_filter($msgs, fn($m) => str_contains($m, 'Michael R.')), 'a review needs the "real customer" tick');
$many = $c0;
for ($i = 0; $i < 13; $i++) $many['states'][] = ['_id' => "s$i", 'code' => array_keys(\ThinkSmart\Site::STATES)[$i + 20], 'licensed' => true];
ok((bool)array_filter(array_column($val->problems($many), 'msg'), fn($m) => str_contains($m, 'holds up to 12')), 'a list past its limit explains why');
$probs = $val->problems($bad);
ok((bool)array_filter($probs, fn($p) => $p['key'] === 'site.phone' && str_contains($p['msg'], '10 digits')), 'the phone problem names its box, so "Fix it" can open it');
$rv = $c0;
$rv['home']['reviews']['items'][] = ['_id' => 'rv2', 'text' => '', 'name' => '', 'real' => true];
$rvMsgs = array_values(array_filter(array_column($val->problems($rv), 'msg'), fn($m) => str_contains($m, 'review')));
ok($rvMsgs === ['The new review needs the customer\'s words and name.'], 'an empty new review gets one line: ' . implode(' | ', $rvMsgs));
// warnings
$faq0 = $c0['faq']['items'][0];
$fit = $schema['fields']['faq.items[].a[].text']['fit'][1];
$longOp = ['op' => 'set', 'path' => ['faq', 'items', ['id' => $faq0['_id']], 'a', ['id' => $faq0['a'][0]['_id']], 'text'], 'value' => str_repeat('Insurers pay less. ', (int)ceil(($fit + 20) / 19)), 'old' => $faq0['a'][0]['text']];
ok((bool)array_filter(array_column($val->warnings($c0, $c0, [$longOp]), 'msg'), fn($m) => str_contains($m, 'may not fit')), "an FAQ answer past $fit letters may not fit (the whole answer is measured)");
$wEmpty = array_column($val->warnings($c0, $c0, [['op' => 'set', 'path' => ['home', 'top', 'kicker'], 'value' => '', 'old' => 'x']]), 'msg');
ok((bool)array_filter($wEmpty, fn($m) => str_contains($m, 'visitors will see a blank')), 'an emptied box is a warning');
$wPrice = array_column($val->warnings($c0, $c0, [['op' => 'set', 'path' => ['site', 'price'], 'value' => 2990, 'old' => 299]]), 'msg');
ok((bool)array_filter($wPrice, fn($m) => str_contains($m, 'from $299 to $2990. Is that right?')), 'a price 10 times bigger asks "Is that right?"');
ok(!$val->warnings($c0, $c0, [['op' => 'set', 'path' => ['site', 'price'], 'value' => 349, 'old' => 299]]), 'a normal price change asks nothing');
// plain words for parts of a field
$ex = new Explain($schema);
ok($ex->field('site.address.street')['label'] === 'Office address: street', 'part of a field has a plain label: ' . $ex->field('site.address.street')['label']);
ok($ex->lines($c0, $c0, [['op' => 'set', 'path' => ['site', 'address', 'zip'], 'value' => '47580', 'old' => '47579']]) === ['changed office address: ZIP code from “47579” to “47580” (all pages)'], 'History names the part, not site.address.zip');
$qLine = $ex->lines($c0, $c0, [['op' => 'set', 'path' => ['faq', 'items', ['id' => $faq0['_id']], 'q'], 'value' => 'New?', 'old' => $faq0['q']]])[0] ?? '';
ok(str_starts_with($qLine, 'changed FAQ: question'), 'labels starting with two capitals keep them: ' . $qLine);
ok($ex->lines($c0, $c0, [['op' => 'set', 'path' => ['home', 'closed', 'photo'], 'value' => 'media/u/a.jpg', 'old' => 'media/x.jpg'], ['op' => 'set', 'path' => ['home', 'closed', 'photoFocus'], 'value' => ['x' => 1, 'y' => 2], 'old' => null]]) === ['changed ' . lcfirst($schema['fields']['home.closed.photo']['label'])], 'a new photo and its important part are one line');
ok(Explain::cut(str_repeat('a', 100)) !== $ex->plainFull(str_repeat('a', 100), 'faq.items[].q', $c0) && \ThinkSmart\Text::length($ex->plainFull(str_repeat('a', 100), 'faq.items[].q', $c0)) === 100, 'plainFull never cuts the words');
ok(Validate::esignWarning('https://na2.documents.adobe.com/public/esignWidget?wid=CBFCIBAA3AAAB*') === null, 'Adobe web form link passes');
ok(str_contains((string)Validate::esignWarning('https://na2.documents.adobe.com/public/agreements/view/123'), 'one person'), 'a one-person agreement link is flagged');

echo "Publish, history, undo\n";
// the live content and pages start from the accepted build of the seed
$b = new Build($paths);
$res = $b->build($c0);
Journal::publish($paths, $res);
(new \ThinkSmart\ContentStore($paths))->save($c0);
$pub = new Publisher($cfg, $people);
$pub->settle();
ok($pub->history->rev() === 1 && $pub->history->head()['kind'] === 'launch', 'the launch-day version is the first History entry');
$live = $pub->live->load();
$startPhone = $live['site']['phone']; // the launch-day main number, whatever the starting content says
$ops = [['op' => 'set', 'path' => ['site', 'phone'], 'value' => '812-774-5050', 'old' => $startPhone]];
$r = $pub->publish($john['id'], $ops, [], 'pub-test-0001', 1);
ok($r['ok'] && $r['entry']['rev'] === 2, 'phone change goes live as change 2');
ok(str_contains((string)file_get_contents("$tmp/site/Contact.dc.html"), '812-774-5050') && !str_contains((string)file_get_contents("$tmp/site/Home.dc.html"), $startPhone), 'the new number is on the pages, the old one gone');
ok(($r['entry']['lines'][0] ?? '') === "changed main phone number from “{$startPhone}” to “812-774-5050” (all pages)", 'History says: ' . ($r['entry']['lines'][0] ?? '?'));
ok(json_decode((string)file_get_contents("$tmp/private/filters.json"), true)['ownPhones'][0] === '8127745050', 'the mailer\'s own-number filter follows');
$mails = $outbox();
$last = $mails ? Json::read(end($mails)) : [];
ok($last && str_contains($last['subject'], 'John changed the website') && str_contains($last['html'], '/edit/undo?t='), 'John gets an email with an Undo button for a phone change');
$r2 = $pub->publish($john['id'], $ops, [], 'pub-test-0001', 1);
ok($r2['ok'] && !empty($r2['again']) && $pub->history->rev() === 2, 'sending the same publish twice changes nothing (dropped connection retry)');
$u = $pub->undo($mun['id'], 2, [], 'undo-test-0001');
ok($u['ok'] && str_contains((string)file_get_contents("$tmp/site/Home.dc.html"), $startPhone) && !str_contains((string)file_get_contents("$tmp/site/Home.dc.html"), '812-774-5050'), 'Undo puts the old number back');
ok($pub->history->head()['kind'] === 'undo' && $pub->history->head()['by'] === $mun['id'], 'the undo is its own History entry');
// delete a question, then bring it back
$live = $pub->live->load();
$order = array_column($live['faq']['items'], '_id');
$del = $order[5];
$newOrder = array_values(array_diff($order, [$del]));
$r = $pub->publish($mun['id'], [['op' => 'list', 'path' => ['faq', 'items'], 'order' => $newOrder, 'add' => [], 'old' => $order]], [], 'pub-test-0002', $pub->history->rev());
ok($r['ok'] && str_starts_with($r['entry']['lines'][0], 'deleted a question “'), 'deleting a question: ' . $r['entry']['lines'][0]);
$rb = $pub->bringBack($john['id'], $r['entry']['rev'], $del, 'bring-test-01');
$live = $pub->live->load();
ok($rb['ok'] && array_column($live['faq']['items'], '_id') === $order, 'Bring back puts it back in its place');
// conflict: John's draft is based on an old version
$base = $pub->history->rev();
$pub->publish($mun['id'], [['op' => 'set', 'path' => ['site', 'price'], 'value' => 349, 'old' => 299]], [], 'pub-test-0003', $base);
$c = $pub->publish($john['id'], [['op' => 'set', 'path' => ['site', 'price'], 'value' => 399, 'old' => 299]], [], 'pub-test-0004', $base);
ok(!$c['ok'] && ($c['conflicts'][0]['byName'] ?? '') === 'Muneerah' && ($c['conflicts'][0]['theirs'] ?? '') === '$349', 'a conflict names who changed it and their value');
$c = $pub->publish($john['id'], [['op' => 'set', 'path' => ['site', 'price'], 'value' => 399, 'old' => 299]], ['site.price' => 'mine'], 'pub-test-0005', $base);
ok($c['ok'] && $pub->live->load()['site']['price'] === 399, '"Use mine" then goes live');
// a change that would break the site never goes live
$c = $pub->publish($john['id'], [['op' => 'set', 'path' => ['site', 'email'], 'value' => 'not an email', 'old' => $live['site']['email']]], [], 'pub-test-0006', $pub->history->rev());
ok(!$c['ok'] && str_contains($c['problems'][0]['msg'] ?? '', 'email'), 'a broken email address is stopped with a plain message');
// whole-site restore
$later = $pub->laterChanges(1, $john['id']);
ok(count($later) >= 2 && $later[0]['byName'] === 'Muneerah', 'restore lists the other person\'s later changes');
$r = $pub->restore($john['id'], 1, 'restore-test-1');
$live = $pub->live->load();
ok($r['ok'] && $live['site']['price'] === 299 && $live['site']['phone'] === $startPhone, 'Put the whole website back to launch day');
foreach ($b->render(Json::read("$tmp/private/history/r1.json.gz")['content']) as $file => $text) {
    if (file_get_contents("$tmp/site/$file") !== $text) { ok(false, "after restore $file matches launch day"); break; }
}
ok(true, 'every page matches launch day again');

echo "Drafts from two copies of the editor\n";
$dr = new Drafts($cfg);
$d1 = $dr->put('drafttest', 3, [['op' => 'set', 'path' => ['site', 'price'], 'value' => 1, 'old' => 299]]);
$d2 = $dr->put('drafttest', 3, [['op' => 'set', 'path' => ['site', 'price'], 'value' => 2, 'old' => 299]], $d1['v']);
ok($d1['v'] === 1 && $d2['v'] === 2 && empty($d2['stale']), 'a save with the current version goes through and counts up (v ' . $d1['v'] . ' → ' . $d2['v'] . ')');
$d3 = $dr->put('drafttest', 3, [['op' => 'set', 'path' => ['site', 'price'], 'value' => 3, 'old' => 299]], $d1['v']);
ok(!empty($d3['stale']) && $d3['v'] === 2 && $dr->get('drafttest')['ops'][0]['value'] === 2, 'an older copy\'s save is refused ("stale") and changes nothing');
$dr->put('drafttest', 3, []);
ok($dr->get('drafttest')['v'] === 0 && $dr->get('drafttest')['ops'] === [], 'an emptied draft starts again at version 0');

echo "An interrupted publish finishes on the next request\n";
$live = $pub->live->load();
$after = $live;
$after['site']['price'] = 450;
$res = $b->build($after);
Json::write($res['dir'] . '/publish.json', ['id' => 'cut-test-01', 'person' => $mun['id'], 'kind' => 'publish', 'meta' => [], 'at' => Clock::now(),
    'lines' => ['changed the price'], 'keys' => ['site.price'], 'sensitive' => false, 'ops' => [], 'content' => $after, 'files' => ['Contact.dc.html'], 'check' => []], false);
Journal::publish($paths, $res, fn($i) => $i === 2); // PHP "stops" after the third file
ok((Journal::read($paths)['state'] ?? '') === 'swapping', 'journal shows a swap in progress');
$pub2 = new Publisher($cfg, $people);
$pub2->settle();
ok((Journal::read($paths)['state'] ?? '') === 'done' && $pub2->live->load()['site']['price'] === 450 && $pub2->history->byPublishId('cut-test-01') !== null, 'the next request finishes the swap and records it');
ok(str_contains((string)file_get_contents("$tmp/site/Services.dc.html"), '$450'), 'the price is on the page');

echo "Photo options in the pages\n";
$opt = $pub2->live->load();
$opt['home']['top']['video']['photo'] = 'media/og-cover.jpg';
$opt['home']['top']['video']['photoFocus'] = ['x' => 30, 'y' => 70];
$opt['home']['closed']['photoFocus'] = ['x' => 10, 'y' => 90];
$files = $b->render($opt, $tr);
$home = $files['Home.dc.html'];
ok(str_contains($home, '<img src="media/og-cover.jpg" alt="" style="width:100%; height:100%; object-fit:cover; object-position:30% 70%; display:block;">'), 'a photo can take the storm video\'s place, cropped at its important part');
ok(!str_contains($home, 'data-ref="rain"') && !str_contains($home, 'data-ref="flash"') && !str_contains($home, 'hero-storm-aerial.mp4'), 'and the rain and lightning go with the video');
ok(str_contains($home, 'object-fit:cover; object-position:10% 90%;'), 'the important part of a photo sets its crop');
$lint = \ThinkSmart\Lint::check('Home.dc.html', $home, $tr['Home.dc.html'], \ThinkSmart\Lint::bindingsIn("$repo/edit/templates"));
ok($lint['errors'] === [], 'the page still passes the runtime checks' . ($lint['errors'] ? ': ' . implode('; ', $lint['errors']) : ''));

echo "Contracts\n";
$pdf = "$tmp/new.pdf";
file_put_contents($pdf, "%PDF-1.4\n% test contract\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
Contracts::keepLive($paths, 'KY', 'docs/think-smart-agreement-kentucky.pdf');
$acc = Contracts::accept($paths, $pdf, 'KY', $john['id'], 'Kentucky.pdf');
ok(strlen($acc['v']) === 16 && count(Contracts::versions($paths, 'KY')) === 2, 'the old contract is kept as a version, the new one added');
try { file_put_contents("$tmp/x.docx", "PK\x03\x04word"); Contracts::accept($paths, "$tmp/x.docx", 'KY', $john['id'], 'x.docx'); ok(false, 'Word file refused'); }
catch (UserError $e) { ok($e->getMessage() === 'This is a Word file. Ask John to send it as a PDF.', 'a Word file gets the plain answer'); }
$live = $pub2->live->load();
$ky = null;
foreach ($live['states'] as $st) if ($st['code'] === 'KY') $ky = $st;
$r = $pub2->publish($john['id'], [['op' => 'set', 'path' => ['states', ['id' => $ky['_id']], 'pdfV'], 'value' => $acc['v'], 'old' => null]], [], 'pdf-test-01', $pub2->history->rev());
ok($r['ok'] && file_get_contents("$tmp/site/docs/think-smart-agreement-kentucky.pdf") === file_get_contents($pdf), 'the new contract is at its permanent address once live');
ok(in_array('put in a new contract (Kentucky)', $r['entry']['lines'], true), 'History: put in a new contract (Kentucky)');
$u = $pub2->undo($john['id'], $r['entry']['rev'], [], 'pdf-undo-01');
ok($u['ok'] && file_get_contents("$tmp/site/docs/think-smart-agreement-kentucky.pdf") === file_get_contents("$repo/docs/think-smart-agreement-kentucky.pdf"), 'Undo puts the earlier contract back');

echo "Signing in\n";
$th = new Throttle($cfg);
ok(Throttle::wait(4) === 0 && Throttle::wait(5) === 60 && Throttle::wait(7) === 240 && Throttle::wait(30) === 3600, 'waits: none for 4 tries, then 1, 2, 4 … up to 60 minutes');
$k = [$th->key('ip', '1.2.3.4'), $th->key('person', $mun['id'])];
for ($i = 0; $i < 4; $i++) $th->fail($k);
ok($th->until($k) === 0, 'four wrong tries: no wait yet');
$t = $th->fail($k);
ok($t > Clock::now() && $t <= Clock::now() + 60, 'the fifth wrong try waits a minute ("Try again at …")');
ok($th->until([$th->key('dev', 'remembered-phone')]) === 0, 'her remembered phone is not slowed down by a stranger');
Clock::set(Clock::now() + 61);
ok($th->until($k) === 0, 'after the wait, she can try again (no hard lockout)');
Clock::set(null);
$codes = new Codes($cfg);
$code = $codes->issue($mun['id'], 'email');
ok(preg_match('/^\d{6}$/', (string)$code) === 1, 'a 6-digit code');
ok($codes->issue($mun['id'], 'email') === null, 'a second code within a minute is held back');
ok($codes->check($mun['id'], '000000' === $code ? '111111' : '000000') === 'wrong' && $codes->check($mun['id'], $code) === 'ok', 'a wrong code, then the right one');
ok($codes->check($mun['id'], $code) === 'none', 'a code works once');
$rc = $codes->issue($mun['id'], 'reset');
for ($i = 0; $i < 5; $i++) $codes->check($mun['id'], '99999' . $i === $rc ? '888888' : '99999' . $i);
ok($codes->check($mun['id'], (string)$rc) !== 'ok', 'five wrong tries cancel the code');
$rescue = $people->newRescueCodes($mun['id']);
ok(count($rescue) === 2 && preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $rescue[0]) === 1, 'two rescue codes like ' . $rescue[0]);
ok($people->useRescueCode($mun['id'], strtolower(str_replace('-', ' ', $rescue[0]))) && !$people->useRescueCode($mun['id'], $rescue[0]), 'a rescue code works once (typed any way)');
$people->remove($mun['id'], $john['id']);
ok($people->findLogin('muneerah') === null && ($people->find($mun['id'])['rescue'] ?? null) === [], 'Remove person: can\'t sign in, rescue codes cancelled');
$people->restore($mun['id']);
ok($people->findLogin('MUNEERAH') !== null, 'brought back from the email link (and usernames ignore capitals)');
try { $people->remove($john['id'], $john['id']); $people->remove($mun['id'], $john['id']); ok(false, 'last person kept'); }
catch (UserError $e) { ok(true, 'the last person can\'t be removed'); }
$people = new People($cfg);
$tok = new Tokens($cfg);
$tt = $tok->make('undo', ['rev' => 2]);
ok(($tok->peek($tt)['action'] ?? '') === 'undo' && $tok->use($tt) !== null && $tok->use($tt) === null, 'email links work once');
ok(\ThinkSmart\Sessions::what('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1') === 'iPhone · Safari', 'device named "iPhone · Safari"');

echo "Photos\n";
$jpg = (string)file_get_contents("$repo/media/og-cover.jpg");
$payload = "Exif\0\0GPS 38.1 -86.9 here";
$exif = "\xFF\xE1" . pack('n', 2 + strlen($payload)) . $payload;
$withExif = substr($jpg, 0, 2) . $exif . "\xFF\xFE" . pack('n', 2 + 9) . 'a comment' . substr($jpg, 2);
file_put_contents("$tmp/up.jpg", $withExif);
$ph = Photos::accept($paths, "$tmp/up.jpg", 'home.damage.cards[].photo');
$saved = (string)file_get_contents("$tmp/site/" . $ph['path']);
ok(preg_match('~^media/u/home-damage-cards-photo-[a-f0-9]{12}\.jpg$~', $ph['path']) === 1, 'saved under a new name: ' . $ph['path']);
ok(!str_contains($saved, 'GPS 38.1') && !str_contains($saved, 'a comment') && @getimagesizefromstring($saved) !== false, 'location and comments removed, picture intact');
ok(is_file("$tmp/site/media/u/.htaccess") && str_contains((string)file_get_contents("$tmp/site/media/u/.htaccess"), 'FilesMatch'), 'the upload folder refuses scripts (FilesMatch)');
file_put_contents("$tmp/fake.jpg", '<?php echo 1; ?>');
try { Photos::accept($paths, "$tmp/fake.jpg", 'x'); ok(false, 'not a photo refused'); } catch (UserError $e) { ok(true, 'a file that isn\'t a photo is refused'); }

echo "Preview marks for \"Fix a word I saw\"\n";
$pv = new Preview($cfg, $pub2);
$html = $b->renderOne($pub2->live->load(), 'Home.dc.html', $trace);
$marked = $pv->annotate($html, $trace);
ok(str_contains($marked, 'data-edit="home.top.title.0"') && str_contains($marked, 'data-edit="home.top.title.1"'), 'the headline lines are marked');
ok((bool)preg_match('/data-edit="faq\.items\.\d+\.(homeQ|q)"/', $marked), 'Home questions point to their FAQ item');
ok(str_contains($marked, 'data-edit="site.phone"') || str_contains($marked, 'site.phone '), 'the call button points to the main phone setting');
ok(!preg_match('/<helmet>(?:(?!<\/helmet>).)*data-edit/s', $marked), 'nothing is marked inside <helmet>');
$stripped = (string)preg_replace('/ data-edit="[^"]*"/', '', $marked);
ok($stripped === $html, 'marks are the only difference from the real page');
ok($pv->editPath('derived.reviews.0.text') === null && $pv->editPath('home.reviews.items.0.text') === 'home.reviews.items.0.text' && $pv->editPath('states.2.name') === 'states.2', 'value paths map to boxes and list items');

Files::rmTree($tmp);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
