<?php
// Lead tests: the public mailer saves every lead first (visitor success = saved), then emails it
// to the confirmed addresses; the daily cap holds leads in the editor with one notice; rate-limited
// leads are kept, not dropped; photos are kept with their claim; the editor reads, imports, tidies
// and re-sends them; lead addresses need their confirmation link; self-repair puts back files.
// Mail never leaves: the test site's config sends it to <private>/outbox.
//   php edit-dev/tests/leads.php
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-leads-tests-' . getmypid();
putenv("TS_WEBROOT=$tmp/site");
putenv("TS_PRIVATE_DIR=$tmp/private");
require "$repo/edit/lib/load.php";

use ThinkSmart\{Clock, Config, Files, Health, Json, Journal, Build, LeadSettings, Leads, MailCap, Paths, People, Publisher, Tokens, UserError};

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
copy("$repo/send-lead.php", "$tmp/site/send-lead.php");
file_put_contents("$tmp/private/config.json", json_encode(['secret' => str_repeat('a', 64), 'dev' => true, 'mail' => 'outbox', 'siteUrl' => 'https://example.test']));
file_put_contents("$tmp/private/filters.json", json_encode(['ownPhones' => ['8127745049', '8128501050'], 'ownEmails' => ['thinksmartpublicadjusting@gmail.com']]));
$paths = Paths::detect();
$cfg = Config::load($paths);

// the mailer, on its own little server
$port = 8796;
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$tmp/site"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
    ['TS_PRIVATE_DIR' => "$tmp/private", 'PATH' => getenv('PATH')]);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
register_shutdown_function(function () use ($proc, $tmp) { proc_terminate($proc); Files::rmTree($tmp); });

function post(string $body, string $type = 'application/json', string $ip = ''): array
{
    global $port;
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: $type\r\nOrigin: http://127.0.0.1:$port\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 10]]);
    $r = @file_get_contents("http://127.0.0.1:$port/send-lead.php", false, $ctx);
    return json_decode((string)$r, true) ?: ['raw' => $r];
}
$outbox = fn() => array_map(fn($f) => Json::read($f), glob("$tmp/private/outbox/*.json") ?: []);
$leads = fn() => array_map(fn($f) => Json::read($f), Leads::files($paths));

echo "Saved first, then emailed\n";
$r = post(json_encode(['kind' => 'claim', 'case_no' => 'TS-2026-0001', 'payload' => ['Full name' => 'Mary Smith', 'Phone' => '812-555-0100', 'Email' => 'mary@example.test', 'What happened' => 'Hail broke the roof.']]));
$all = $leads();
ok(($r['success'] ?? false) === true && count($all) === 1, 'a claim is saved and the visitor sees success');
$l = $all[0] ?? [];
ok(($l['name'] ?? '') === 'Mary Smith' && ($l['phone'] ?? '') === '812-555-0100' && ($l['email'] ?? '') === 'mary@example.test' && ($l['payload']['What happened'] ?? '') === 'Hail broke the roof.', 'with the name, phone, email and every field');
$mail = $outbox();
ok(count($mail) === 1 && $mail[0]['to'] === ['thinksmartpublicadjusting@gmail.com'] && ($mail[0]['headers']['X-TS-Lead'] ?? '') === $l['id'], 'emailed to today\'s address, tagged with the lead (for bounces)');
ok(($l['mail']['state'] ?? '') === 'sent', 'the lead records that it was emailed');
ok((Leads::last($paths)['id'] ?? '') === $l['id'], '"Last lead received" knows it');
ok(!is_dir("$tmp/site/leads") && !glob("$tmp/site/*.json") && !glob("$tmp/site/*.log"), 'nothing written into the web folder');

echo "Where leads go\n";
LeadSettings::addPending($cfg, 'claims@example.test', null);
$conf = array_values(array_filter($outbox(), fn($m) => str_contains($m['subject'] ?? '', 'please confirm')));
ok(count($conf) === 1 && $conf[0]['to'] === ['claims@example.test'] && str_contains($conf[0]['html'], '/edit/confirm?t='), 'a new address gets a test lead with a confirmation link');
ok(LeadSettings::recipients($cfg) === ['claims@example.test'], 'while nothing is confirmed, leads go to the waiting address too (and are always saved)');
LeadSettings::confirm($cfg, 'claims@example.test');
LeadSettings::addPending($cfg, 'typo@exampel.test', null);
ok(LeadSettings::recipients($cfg) === ['claims@example.test'], 'once one is confirmed, a new address waits for its link');
post(json_encode(['kind' => 'contact', 'payload' => ['Name' => 'Bob', 'Phone' => '8125550199', 'Message' => 'Call me']]));
$bob = array_values(array_filter($outbox(), fn($m) => ($m['kind'] ?? '') === 'lead' && str_contains($m['subject'] ?? '', 'Bob')));
ok(($bob[0]['to'] ?? null) === ['claims@example.test'], 'the mailer follows the editor\'s settings');
try { LeadSettings::remove($cfg, 'claims@example.test'); ok(false, 'the last confirmed address can\'t be removed'); }
catch (UserError $e) { ok(true, 'the last confirmed address can\'t be removed'); }
$before = LeadSettings::remove($cfg, 'typo@exampel.test');
LeadSettings::restore($cfg, $before);
ok(count(LeadSettings::load($cfg)['leadTo']) === 2, 'Undo puts the addresses back');

echo "Chats, photos, limits\n";
post(json_encode(['kind' => 'chat', 'payload' => ['Stage' => 'first', 'Chat transcript' => "Think Smart: Hi, call us at 812-774-5049\nVisitor: my number is (812) 555-0123 thanks"]]));
$chat = array_values(array_filter($leads(), fn($x) => $x['kind'] === 'chat'))[0] ?? [];
ok($chat['kind'] === 'chat' && $chat['phone'] === '(812) 555-0123', 'a chat lead keeps the visitor\'s number, not the business\'s own');
$boundary = 'b' . bin2hex(random_bytes(6));
$jpg = (string)file_get_contents("$repo/media/og-cover.jpg");
$body = "--$boundary\r\nContent-Disposition: form-data; name=\"case_no\"\r\n\r\nTS-2026-0001\r\n--$boundary\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nMary Smith\r\n"
    . "--$boundary\r\nContent-Disposition: form-data; name=\"photos[]\"; filename=\"roof.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n$jpg\r\n--$boundary--\r\n";
$r = post($body, "multipart/form-data; boundary=$boundary");
$ph = array_values(array_filter($leads(), fn($x) => $x['kind'] === 'photos'))[0] ?? [];
ok(($r['success'] ?? false) && $ph['kind'] === 'photos' && is_file("$tmp/private/leads/photos/TS-2026-0001/photo-1.jpg"), 'claim photos are saved with their case number');
$claim = null;
foreach ($leads() as $x) if ($x['kind'] === 'claim') $claim = $x;
ok(Leads::photos($paths, ['case_no' => $claim['case_no']]) === ['photo-1.jpg'], 'and show with the claim in the editor');
// the daily email allowance
Json::write("$tmp/private/mail-count.json", ['day' => Clock::local(Clock::now())->format('Y-m-d'), 'n' => 400], false);
$n0 = count($outbox());
post(json_encode(['kind' => 'contact', 'payload' => ['Name' => 'Over Cap', 'Message' => 'one']]));
post(json_encode(['kind' => 'contact', 'payload' => ['Name' => 'Over Cap 2', 'Message' => 'two']]));
$notes = array_values(array_filter($outbox(), fn($m) => str_contains($m['subject'] ?? '', 'waiting')));
$held = array_filter($leads(), fn($x) => ($x['mail']['state'] ?? '') === 'held');
ok(count($held) === 2 && count($notes) === 1 && count($outbox()) === $n0 + 1, 'over the daily allowance: leads kept (not emailed), one "leads are waiting" email');
ok(MailCap::take($paths, 'code') && !MailCap::take($paths, 'lead'), 'sign-in codes keep their reserve after lead emails stop');
Json::write("$tmp/private/mail-count.json", ['day' => '2000-01-01', 'n' => 0], false);
// many from one visitor: kept, not dropped
for ($i = 0; $i < 12; $i++) $rr = post(json_encode(['kind' => 'chat', 'payload' => ['Stage' => 'follow-up', 'Chat transcript' => "Visitor: message $i"]]));
$rated = array_filter($leads(), fn($x) => ($x['mail']['state'] ?? '') === 'held' && $x['kind'] === 'chat');
ok(($rr['success'] ?? false) === true && count($rated) >= 1, 'a visitor over the rate limit still gets success; the lead is kept, not emailed');
$r = post(json_encode(['kind' => 'claim', '_hp' => 'bot', 'payload' => ['Full name' => 'Bot']]));
ok(($r['success'] ?? false) === true && !array_filter($leads(), fn($x) => ($x['name'] ?? '') === 'Bot'), 'the honeypot still drops bots quietly');

echo "In the editor\n";
$list = Leads::listing($paths);
ok(count($list) >= 5 && $list[0]['at'] >= end($list)['at'] && isset($list[0]['what'], $list[0]['when']), 'the Leads screen lists them newest first');
$det = Leads::detail($paths, Leads::get($paths, $claim['id']));
ok($det['rows'][0] === ['Full name', 'Mary Smith'] && $det['caseNo'] === 'TS-2026-0001', 'a lead opens with its fields as plain text');
Leads::import($cfg, ['received' => gmdate('c', Clock::now() - 5 * 86400), 'kind' => 'contact', 'case_no' => '', 'ip' => '1.2.3.4', 'emailed' => true, 'payload' => ['Name' => 'Old Lead', 'Phone' => '8125550000']]);
Leads::import($cfg, ['received' => gmdate('c', Clock::now() - 120 * 86400), 'kind' => 'contact', 'payload' => ['Name' => 'Too Old']]);
$names = array_column(Leads::listing($paths), 'name');
ok(in_array('Old Lead', $names, true) && !in_array('Too Old', $names, true), 'old lead logs are imported (only the last 90 days)');
$old = Leads::get($paths, Leads::listing($paths)[0]['id']);
foreach (Leads::files($paths) as $f) if (str_contains((string)file_get_contents($f), 'Old Lead')) { touch($f, Clock::now() - 100 * 86400); $j = Json::read($f); $j['at'] = Clock::now() - 100 * 86400; file_put_contents($f, json_encode($j)); touch($f, Clock::now() - 100 * 86400); }
touch("$tmp/private/leads/photos/TS-2026-0001", Clock::now() - 20 * 86400);
Leads::tidy($paths);
ok(!in_array('Old Lead', array_column(Leads::listing($paths), 'name'), true) && !is_dir("$tmp/private/leads/photos/TS-2026-0001"), 'leads go after 90 days, photos after 14');

echo "Health and self-repair\n";
foreach (['Home.dc.html', 'About.dc.html', 'Services.dc.html', 'FAQ.dc.html', 'Contact.dc.html', 'Claim.dc.html', 'ChatWidget.dc.html', '404.html', 'index.html', 'sitemap.xml', 'support.js'] as $f) copy("$repo/$f", "$tmp/site/$f");
@mkdir("$tmp/site/edit/app", 0777, true);
file_put_contents("$tmp/site/edit/app/app.js", "// the editor\n");
$people = new People($cfg);
$people->add('John', 'john', 'john@example.test', 'storm-test-2026-alpha', true);
$people->save();
$pub = new Publisher($cfg, $people);
$pub->settle();
$res = (new Build($paths))->build($pub->live->load());
Journal::publish($paths, $res);
Health::snapshotPristine($cfg);
ok(is_file("$tmp/private/pristine/manifest.json") && count(Json::read("$tmp/private/pristine/manifest.json")['files']) >= 3, 'a fingerprinted copy of the editor\'s own files is kept');
file_put_contents("$tmp/site/edit/app/app.js", "// changed outside the editor\n");
file_put_contents("$tmp/site/Home.dc.html", 'hacked');
$fixed = Health::check($cfg, $people, true);
ok(in_array('edit/app/app.js', $fixed, true) && in_array('Home.dc.html', $fixed, true) && file_get_contents("$tmp/site/edit/app/app.js") === "// the editor\n"
    && file_get_contents("$tmp/site/Home.dc.html") === file_get_contents($res['dir'] . '/site/Home.dc.html'), 'files changed outside the editor are put back');
ok(((new \ThinkSmart\History($cfg))->head()['kind'] ?? '') === 'repair' && array_filter($outbox(), fn($m) => str_contains($m['subject'] ?? '', 'repaired itself')), 'recorded in History, and John is told');
file_put_contents("$tmp/site/.htaccess", "# cPanel's own\n");
ok(Health::check($cfg, $people, true) === [] && file_get_contents("$tmp/site/.htaccess") === "# cPanel's own\n", '.htaccess is never touched');
$st = Health::status($cfg, true);
ok($st['lastLead'] !== null && str_contains(implode(' ', array_column($st['lines'], 'text')), 'put back'), 'the status line shows the last lead and, for John, the repair');
$l2 = Leads::get($paths, Leads::listing($paths)[0]['id']);
$l2['mail']['state'] = 'bounced';
Leads::put($paths, $l2);
ok(str_contains(implode(' ', array_column(Health::status($cfg, false)['lines'], 'text')), "didn't reach your email"), 'a bounced lead turns the status red for everyone');
file_put_contents("$tmp/site/edit/app/app.js", "// broken\n");
$rep = Health::repairEditor($cfg, $people);
ok(in_array('edit/app/app.js', $rep, true) && file_get_contents("$tmp/site/edit/app/app.js") === "// the editor\n", '"Repair the editor" copies its own files back');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
