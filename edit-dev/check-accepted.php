<?php
// The accepted build (Gate 2, D1–D10 accepted 2026-10-18): renders edit/templates with
// edit/seed/content.json and compares every file with edit-dev/gate2/ACCEPTED.sha256.
//   php edit-dev/check-accepted.php          compare
//   php edit-dev/check-accepted.php --write  record the current build as accepted (after a review)
declare(strict_types=1);
require __DIR__ . '/../edit/lib/load.php';

use ThinkSmart\{Build, Paths};

$repo = dirname(__DIR__);
$list = __DIR__ . '/gate2/ACCEPTED.sha256';
$seed = json_decode((string)file_get_contents("$repo/edit/seed/content.json"), true, 512, JSON_THROW_ON_ERROR);
$files = (new Build(new Paths($repo, sys_get_temp_dir(), "$repo/edit")))->render($seed);
if (in_array('--write', $argv, true)) {
    $out = "# Accepted build: edit/templates + edit/seed/content.json (Gate 2, D1–D10 accepted 2026-10-18)\n";
    foreach ($files as $f => $text) $out .= hash('sha256', $text) . "  $f\n";
    file_put_contents($list, $out);
    echo "recorded " . count($files) . " files\n";
    exit(0);
}
$want = [];
foreach (file($list, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if ($line === '' || $line[0] === '#') continue;
    [$sha, $f] = preg_split('/\s+/', $line, 2);
    $want[$f] = $sha;
}
$bad = 0;
foreach ($files as $f => $text) {
    $ok = ($want[$f] ?? '') === hash('sha256', $text);
    if (!$ok) $bad++;
    printf("%s  %s\n", $ok ? 'SAME   ' : 'CHANGED', $f);
}
echo $bad ? "$bad file(s) differ from the accepted build\n" : "matches the accepted build\n";
exit($bad ? 1 : 0);
