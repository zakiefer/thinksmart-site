<?php
// Makes a throwaway copy of the website and a private folder for trying the editor locally:
//   php edit-dev/editor/dev-setup.php <dir>        -> <dir>/site (web root) and <dir>/private
// The copy's edit/ is a link to the real one, so code changes show at once. Publishing in the
// editor writes only into <dir>/site. Logins come from dev-people.json.
declare(strict_types=1);

$dir = rtrim($argv[1] ?? '', '/');
if ($dir === '') { fwrite(STDERR, "usage: php dev-setup.php <dir>\n"); exit(2); }
$repo = dirname(__DIR__, 2);
putenv("TS_WEBROOT=$dir/site");
putenv("TS_PRIVATE_DIR=$dir/private");
require "$repo/edit/lib/load.php";

use ThinkSmart\{Config, Files, People, Paths};

Files::rmTree("$dir/site");
Files::rmTree("$dir/private");
@mkdir("$dir/site", 0755, true);
@mkdir("$dir/private", 0700, true);
// the site's own files (not the developer folders)
$skip = ['.git', 'edit', 'edit-dev', '.github', 'node_modules'];
$copy = function (string $from, string $to) use (&$copy, $skip, $repo) {
    foreach (scandir($from) as $f) {
        if ($f === '.' || $f === '..' || ($from === $repo && in_array($f, $skip, true))) continue;
        if ($from === $repo && $f[0] === '.' && $f !== '.htaccess') continue;
        if (is_dir("$from/$f")) { @mkdir("$to/$f", 0755, true); $copy("$from/$f", "$to/$f"); }
        else copy("$from/$f", "$to/$f");
    }
};
$copy($repo, "$dir/site");
symlink("$repo/edit", "$dir/site/edit");
file_put_contents("$dir/private/config.json", json_encode([
    'secret' => bin2hex(random_bytes(32)), 'dev' => true, 'mail' => 'outbox', 'siteUrl' => getenv('TS_SITE_URL') ?: 'http://localhost:8790',
], JSON_PRETTY_PRINT) . "\n");
$paths = Paths::detect();
$cfg = Config::load($paths);
$people = new People($cfg);
$seed = json_decode((string)file_get_contents(__DIR__ . '/dev-people.json'), true);
foreach ($seed['people'] as $p) $people->add($p['name'], $p['username'], $p['email'], $p['password'], $p['alerts']);
$people->save();
echo "site:    $dir/site\nprivate: $dir/private\npeople:  " . count($seed['people']) . "\n";
