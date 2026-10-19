<?php
// Builder tests: encoders, input rules, lint, the swap journal and the content store.
//   php edit-dev/tests/run.php
// Everything runs in a temporary folder; the repo's own files are never written.
declare(strict_types=1);
require __DIR__ . '/../../edit/lib/load.php';

use ThinkSmart\{Build, BuildError, ContentStore, Encode, Files, Input, Journal, Lint, Paths, Template};

$pass = 0;
$fail = 0;
function ok(bool $c, string $what): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
}
function throws(callable $f, string $what): void
{
    try { $f(); ok(false, $what); } catch (BuildError $e) { ok(true, $what . ' (' . strtok($e->getMessage(), "\n") . ')'); }
}

$repo = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-tests-' . getmypid();
Files::rmTree($tmp);
mkdir("$tmp/site", 0777, true);
$seed = json_decode((string)file_get_contents("$repo/edit/seed/content.json"), true);

echo "Encoders\n";
ok(Encode::text('a < b & "c" > d', true) === 'a &lt; b &amp; "c" &gt; d', 'text escapes < & >');
ok(Encode::text('iPhone=5', true) === 'iPhone&#61;5', 'text "=" inside <x-dc> is written &#61;');
ok(Encode::text('a=b', false) === 'a=b', 'text "=" outside <x-dc> stays');
ok(Encode::attr('say "hi" & <go>', true) === 'say &quot;hi&quot; &amp; &lt;go&gt;', 'attribute escapes quotes');
ok(Encode::js("it's </script><!-- \"x\"") === "'it\\'s \\x3C/script\\x3E\\x3C!-- \"x\"'", 'JS literal escapes quotes and < >');
ok(Encode::js("line\nbreak\u{2028}") === "'line\\nbreak\\u2028'", 'JS literal escapes line breaks');
ok(Encode::json(['a' => '</script>']) === '{"a":"\u003C/script\u003E"}', 'JSON-LD escapes < >');
foreach (['javascript:alert(1)', 'data:text/html,x', 'http://example.com', 'https://ex ample.com', '//evil.com', '../secret', 'tel:12ab'] as $bad) {
    ok(!Encode::urlAllowed($bad), "link refused: $bad");
}
foreach (['https://na2.documents.adobe.com/public/esignWidget?wid=CBF*', 'tel:8127745049', 'mailto:a@b.com?subject=Damage%20photos', 'docs/x.pdf', 'Claim.dc.html#photos', '/Claim.dc.html', '#main'] as $good) {
    ok(Encode::urlAllowed($good), "link allowed: $good");
}

echo "Input rules\n";
ok(strpos(Input::clean('a {{ b }} c'), '{{') === false && strpos(Input::clean('a {{ b }} c'), '}}') === false, '"{{" and "}}" are broken up');
ok(strpos(Input::clean('{{{'), '{{') === false, '"{{{" is broken up too');
ok(Input::clean("x\u{E000}1\u{E001}y⟦z⟧") === 'x1yz', 'private-use characters and template brackets are removed');
ok(Input::camelEqualsRisk(' get iPhone= now') && !Input::camelEqualsRisk('a = b'), 'camelCase "=" risk is detected');

echo "Build with hostile words\n";
$evil = $seed;
$evil['home']['top']['text'] = ['Try {{ submit }} and <script>alert(1)</script> & iPhone=5 "quoted" ', ['b' => 'bold </strong><img src=x onerror=alert(1)>']];
$evil['about']['top']['photoAlt'] = '"><svg onload=alert(1)>';
$evil['chat']['intro'] = "</script><script>alert('x')</script>";
$evil['seo']['home']['title'] = ['Title </title><script>x</script> ', ['ref' => 'stateCodes']];
$evil['site']['social'][0]['url'] = 'https://www.facebook.com/SmartAdjusting/';
$p = new Paths("$tmp/site", "$tmp/private", "$repo/edit");
$b = new Build($p);
$res = $b->build($evil);
$home = (string)file_get_contents($res['dir'] . '/site/Home.dc.html');
$chat = (string)file_get_contents($res['dir'] . '/site/ChatWidget.dc.html');
$about = (string)file_get_contents($res['dir'] . '/site/About.dc.html');
ok(strpos($home, '<script>alert(1)') === false && strpos($home, '&lt;script&gt;alert(1)') !== false, 'script in text is escaped');
ok(strpos($home, '{{ submit }}') === false, '"{{ submit }}" in text can\'t become a binding');
ok(strpos($home, 'iPhone&#61;5') !== false, '"iPhone=5" is written with &#61;');
ok(strpos($home, '<img src=x') === false, 'markup inside a bold run is escaped');
ok(strpos($about, '"><svg') === false && strpos($about, '&quot;&gt;&lt;svg') !== false, 'quote can\'t break out of an attribute');
$plainChat = (string)$b->render($seed)['ChatWidget.dc.html'];
ok(substr_count($chat, '</script') === substr_count($plainChat, '</script') && strpos($chat, '\\x3C/script\\x3E') !== false, 'chat text can\'t close the page script');
ok(strpos($home, '<title>Title &lt;/title&gt;') !== false, '<title> text is escaped');

echo "Links refused\n";
$bad = $seed;
$bad['site']['social'][0]['url'] = 'javascript:alert(1)';
throws(fn() => $b->build($bad), 'a javascript: social link stops the build');
$bad = $seed;
$bad['states'][0]['esign'] = 'http://phish.example.com/sign';
throws(fn() => $b->build($bad), 'a non-https e-sign link stops the build');

$bad = $seed;
$bad['site']['email'] = 'a@b.com?bcc=x@evil.com';
throws(fn() => $b->build($bad), 'an email setting with extra parts stops the build');
$bad = $seed;
$bad['site']['phone'] = '812-774-504';
throws(fn() => $b->build($bad), 'a 9-digit phone stops the build');

echo "Lint\n";
$bindings = Lint::bindingsIn("$repo/edit/templates");
$okPage = '<!DOCTYPE html><html><body><x-dc><helmet><style>a{}</style><link href="https://fonts.googleapis.com/css2?family=X" rel="stylesheet"></helmet><input required="{{ true }}"></x-dc><script type="text/x-dc" data-dc-script>class Component extends DCLogic {}</script></body></html>';
ok(Lint::check('T.dc.html', $okPage, [], $bindings)['errors'] === [], 'a clean page passes');
$cases = [
    'bare required' => str_replace('required="{{ true }}"', 'required', $okPage),
    'camelCase attribute' => str_replace('<input ', '<input tabIndex="0" ', $okPage),
    'window.__resources' => str_replace('class Component', 'window.__resources = {}; class Component', $okPage),
    'fonts link before style' => str_replace('<style>a{}</style><link href="https://fonts.googleapis.com/css2?family=X" rel="stylesheet">', '<link href="https://fonts.googleapis.com/css2?family=X" rel="stylesheet"><style>a{}</style>', $okPage),
    'unknown binding' => str_replace('{{ true }}', '{{ secret }}', $okPage),
    'text rewritten by support.js' => str_replace('<input', '<p> say aB= hi</p><input', $okPage),
    'second <x-dc>' => str_replace('<input', '<x-dc></x-dc><input', $okPage),
];
foreach ($cases as $what => $page) ok(Lint::check('T.dc.html', $page, [], $bindings)['errors'] !== [], "lint catches: $what");
$built = $b->render($seed, $traces);
$errs = [];
foreach ($built as $f => $t) $errs = array_merge($errs, Lint::check($f, $t, $traces[$f], $bindings)['errors']);
ok($errs === [], 'today\'s content builds with no lint errors');

echo "Swap journal\n";
foreach (['Home.dc.html', 'About.dc.html', '.htaccess'] as $f) file_put_contents("$tmp/site/$f", "old $f\n");
$ht = Files::sha("$tmp/site/.htaccess");
$r1 = $b->build($seed);
Journal::publish($p, $r1);
ok(Files::sha("$tmp/site/Home.dc.html") === $r1['files']['Home.dc.html']['sha'], 'publish puts the built Home page live');
ok(count(array_filter(array_keys($r1['files']), fn($f) => Files::sha("$tmp/site/$f") === $r1['files'][$f]['sha'])) === 10, 'all 10 files are live');
ok(Files::sha("$tmp/site/.htaccess") === $ht, '.htaccess is never touched');
ok(Journal::read($p)['state'] === 'done', 'journal says done');
$ownPhones = array_values(array_unique([preg_replace('/\D/', '', $seed['site']['phone']), preg_replace('/\D/', '', $seed['site']['phone2'])]));
ok(json_decode((string)file_get_contents("$tmp/private/filters.json"), true)['ownPhones'] === $ownPhones, 'publish installs the own-number filters for the mailer');
Journal::rollback($p);
ok(file_get_contents("$tmp/site/Home.dc.html") === "old Home.dc.html\n" && !is_file("$tmp/site/FAQ.dc.html"), 'undo puts back the old files (and removes new ones)');

// interrupted after 3 files, then the next run finishes it
$r2 = $b->build($evil);
Journal::publish($p, $r2, fn($i) => $i === 2);
ok(Journal::read($p)['state'] === 'swapping', 'a swap stopped halfway is left "swapping"');
$mixed = array_filter(array_keys($r2['files']), fn($f) => Files::sha("$tmp/site/$f") === $r2['files'][$f]['sha']);
ok(count($mixed) === 3, 'only the first 3 files changed before the stop');
ok(Journal::recover($p) === 'finished the interrupted swap', 'recover finishes the swap');
ok(count(array_filter(array_keys($r2['files']), fn($f) => Files::sha("$tmp/site/$f") === $r2['files'][$f]['sha'])) === 10, 'after recovery all files are the new build');

// interrupted, and the side folder is damaged: recovery puts the previous files back
$r3 = $b->build($seed);
$before = Files::sha("$tmp/site/About.dc.html");
Journal::publish($p, $r3, fn($i) => $i === 1);
file_put_contents($r3['dir'] . '/site/Services.dc.html', 'damaged');
ok(Journal::recover($p) === 'put the previous files back', 'a damaged build is rolled back');
ok(Files::sha("$tmp/site/About.dc.html") === $before, 'the previous About page is live again');

echo "Content store\n";
$cs = new ContentStore($p);
$cs->save($seed);
$cs->save($seed + ['_x' => 1]);
file_put_contents($p->content(), '{ broken');
[$c, $from] = $cs->load();
ok(str_ends_with($from, 'content.json.good') && isset($c['site']), 'an unreadable content file falls back to the last good copy');
ok(is_array(json_decode((string)file_get_contents($p->content()), true)), 'and the good copy is put back in place');

Files::rmTree($tmp);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
