<?php
// Builds the whole site from content.json into a private side folder and checks it.
//
//   php render.php                         build from <private>/content.json (created from
//                                          seed/content.json the first time)
//   php render.php --content=FILE          build from another content file
//   php render.php --private=DIR           private folder (default: beside the site folder,
//                                          or $TS_PRIVATE_DIR)
//   php render.php --publish --webroot=DIR swap the build into DIR with the journal
//   php render.php --recover --webroot=DIR finish or undo an interrupted swap
//   php render.php --undo --webroot=DIR    put back what the last swap replaced
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib/load.php';

use ThinkSmart\{Build, BuildError, ContentStore, Journal, Paths};

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { fwrite(STDERR, "Unknown argument $a\n"); exit(2); }
    $opt[$m[1]] = $m[2] ?? true;
}
$over = [];
if (isset($opt['private'])) $over['private'] = (string)$opt['private'];
if (isset($opt['webroot'])) $over['webroot'] = (string)$opt['webroot'];
$paths = Paths::detect($over);

try {
    if (isset($opt['recover']) || isset($opt['undo']) || isset($opt['publish'])) {
        if (!isset($opt['webroot'])) throw new BuildError('Say where the live site is: --webroot=DIR');
    }
    if (isset($opt['recover'])) { echo Journal::recover($paths), "\n"; exit(0); }
    if (isset($opt['undo'])) { Journal::rollback($paths); echo "Put back the files the last swap replaced.\n"; exit(0); }

    if (isset($opt['content'])) {
        $content = json_decode((string)file_get_contents((string)$opt['content']), true, 512, JSON_THROW_ON_ERROR);
        $from = (string)$opt['content'];
    } else {
        [$content, $from] = (new ContentStore($paths))->load();
    }
    $b = new Build($paths);
    $t0 = microtime(true);
    $res = $b->build($content);
    printf("Built %d files from %s in %.2fs\n  side folder: %s\n", count($res['files']), $from, microtime(true) - $t0, $res['dir'] . '/site');
    foreach ($res['files'] as $file => $m) printf("  %-20s %7d bytes  %s\n", $file, $m['bytes'], substr($m['sha'], 0, 12));
    foreach ($b->warnings as $w) echo "  note: $w\n";
    echo "  checks: passed\n";
    if (isset($opt['publish'])) {
        Journal::publish($paths, $res);
        echo "Published into {$paths->webroot}\n";
    }
    $b->prune(5);
} catch (BuildError | JsonException $e) {
    fwrite(STDERR, "Not built: " . $e->getMessage() . "\n");
    exit(1);
}
