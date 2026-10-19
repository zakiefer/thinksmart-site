<?php
// Makes a throwaway copy of the website for trying the installer on this computer: no
// config, no logins, no private folder (the installer makes those), laid out like the hosting:
//
//   <dir>/public_html                 the website; its edit/ holds only install-code.hash
//                                     (the router runs the real editor code from the repo)
//   <dir>/thinksmart-leads/           old lead logs as the old form wrote them (one lead is
//                                     older than 90 days, one line is damaged)
//   <dir>/public_html/think-smart-launch-kit-2026-10-18.zip   a leftover setup zip
//   <dir>/tmp                         the installer's wrong-code count (TMPDIR)
//
//   php edit-dev/launch-kit/try-install.php <dir> <setup code>
//   TS_INSTALL_DEV=1 TS_WEBROOT=<dir>/public_html TS_PRIVATE_DIR=<dir>/thinksmart-private TMPDIR=<dir>/tmp \
//     PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=16M -d post_max_size=20M \
//     -S localhost:8792 -t <dir>/public_html edit-dev/editor/router.php
//   open http://localhost:8792/edit/install
//
// TS_INSTALL_DEV=1 (only honoured on localhost) allows plain http and keeps every email in
// <dir>/thinksmart-private/outbox/.
declare(strict_types=1);

$dir = rtrim($argv[1] ?? '', '/');
$code = $argv[2] ?? '';
if ($dir === '' || $code === '') { fwrite(STDERR, "usage: php try-install.php <dir> <setup code>\n"); exit(2); }
$repo = dirname(__DIR__, 2);
require "$repo/edit/lib/load.php";

use ThinkSmart\{Files, Installer};

foreach (['public_html', 'thinksmart-private', 'thinksmart-leads', 'tmp'] as $d) Files::rmTree("$dir/$d");
@mkdir("$dir/public_html/edit", 0755, true);
@mkdir("$dir/tmp", 0700, true);
$skip = ['.git', 'edit', 'edit-dev', '.github', 'node_modules', '.claude'];
$copy = function (string $from, string $to) use (&$copy, $skip, $repo) {
    foreach (scandir($from) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f === '.DS_Store' || ($from === $repo && in_array($f, $skip, true))) continue;
        if ($from === $repo && $f[0] === '.' && !in_array($f, ['.htaccess', '.user.ini'], true)) continue;
        if ($from === "$repo/media" && $f === 'u') continue;
        if (is_dir("$from/$f")) { @mkdir("$to/$f", 0755, true); $copy("$from/$f", "$to/$f"); }
        else copy("$from/$f", "$to/$f");
    }
};
$copy($repo, "$dir/public_html");
file_put_contents("$dir/public_html/edit/install-code.hash", Installer::codeHash($code) . "\n");
file_put_contents("$dir/public_html/think-smart-launch-kit-2026-10-18.zip", "PK\x05\x06" . str_repeat("\0", 18));

// old lead logs (the format today's send-lead.php wrote before the editor)
@mkdir("$dir/thinksmart-leads", 0700);
$day = fn(int $n) => gmdate('c', time() - $n * 86400);
$lines = [
    ['received' => $day(120), 'kind' => 'claim', 'case_no' => 'TS-OLD-0001', 'ip' => '203.0.113.9', 'emailed' => true,
        'payload' => ['Full name' => 'Old Lead (older than 90 days)', 'Phone' => '812-555-0100']],
    ['received' => $day(40), 'kind' => 'chat', 'case_no' => '', 'ip' => '203.0.113.10', 'emailed' => true,
        'payload' => ['Stage' => 'new lead', 'Chat transcript' => "Visitor: Water in the basement after the storm\nVisitor: 812-555-0142"]],
    ['received' => $day(12), 'kind' => 'contact', 'case_no' => '', 'ip' => '203.0.113.11', 'emailed' => true,
        'payload' => ['Full name' => 'Pat Example', 'Phone' => '(812) 555-0151', 'Message' => 'Please call me about hail damage.']],
    ['received' => $day(3), 'kind' => 'claim', 'case_no' => 'TS-2026-1015-4065', 'ip' => '203.0.113.12', 'emailed' => false,
        'payload' => ['Case number' => 'TS-2026-1015-4065', 'Full name' => 'Sam Example', 'Phone' => '812-555-0150', 'Email' => 'sam@example.test', 'Damage types' => 'Hail']],
    ['received' => $day(3), 'kind' => 'photos', 'case_no' => 'TS-2026-1015-4065', 'name' => 'Sam Example', 'ip' => '203.0.113.12', 'photos' => 2, 'emailed' => true],
];
$byMonth = [];
foreach ($lines as $l) $byMonth[substr($l['received'], 0, 7)][] = json_encode($l, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$first = true;
foreach ($byMonth as $m => $ls) {
    if ($first) { $ls[] = '{"received":"2026-'; $first = false; } // a line cut short (a full disk, once)
    file_put_contents("$dir/thinksmart-leads/leads-$m.log", implode("\n", $ls) . "\n");
}
echo "site:    $dir/public_html\nprivate: $dir/thinksmart-private (made by the installer)\nold leads: " . count($lines) . " in $dir/thinksmart-leads\n";
