<?php
// Builds the launch kit: one zip to upload into public_html and extract there (cPanel → File
// Manager → Upload, then Extract). It holds the website (pages, support.js, vendor/, media/,
// docs/, send-lead.php, .htaccess, .user.ini, robots.txt, sitemap.xml, index.html, 404.html),
// the editor (edit/, never edit-dev/), and edit/install-code.hash: the hash of the setup code
// that /edit/install asks for first. Next to the zip it puts the install guide and John's
// launch-kit checklist with the setup code written in (for his Google Drive).
//
//   php edit-dev/make-zip.php <setup code> [--out=DIR]
//
// Output goes to edit-dev/launch-kit/out/ (git-ignored). Reads the working copy as it is.
declare(strict_types=1);

$repo = dirname(__DIR__);
require "$repo/edit/lib/load.php";

use ThinkSmart\{Clock, Installer, People, Zip};

$out = "$repo/edit-dev/launch-kit/out";
$code = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--out=')) $out = rtrim(substr($a, 6), '/');
    elseif ($code === null && !str_starts_with($a, '--')) $code = $a;
}
$suggest = function (): string {
    $c = '';
    for ($i = 0; $i < 12; $i++) $c .= People::RESCUE_ALPHABET[random_int(0, strlen(People::RESCUE_ALPHABET) - 1)];
    return substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8, 4);
};
if ($code === null) {
    fwrite(STDERR, "usage: php edit-dev/make-zip.php <setup code> [--out=DIR]\n"
        . "The setup code is typed at /edit/install (capitals, spaces and dashes don't matter). For example: " . $suggest() . "\n");
    exit(2);
}
if (strlen(Installer::normalizeCode($code)) < 10) {
    fwrite(STDERR, "The setup code needs at least 10 letters or numbers. For example: " . $suggest() . "\n");
    exit(2);
}

// ---------------------------------------------------------------- what goes in
$files = [];   // zip name => source file
$dirs = [];
$top = array_merge(array_map('basename', glob("$repo/*.dc.html") ?: []),
    ['404.html', 'index.html', 'sitemap.xml', 'robots.txt', 'send-lead.php', 'support.js', '.htaccess', '.user.ini']);
foreach ($top as $f) {
    if (!is_file("$repo/$f")) { fwrite(STDERR, "Missing from the site: $f\n"); exit(1); }
    $files[$f] = "$repo/$f";
}
$skipName = ['.DS_Store', 'Thumbs.db', '.git', 'node_modules'];
$skipPath = ['edit/install-code.hash', 'edit/.installed', 'media/u'];
$walk = function (string $rel) use (&$walk, &$files, &$dirs, $repo, $skipName, $skipPath): void {
    $abs = "$repo/$rel";
    $dirs[] = $rel;
    foreach (scandir($abs) ?: [] as $f) {
        if ($f === '.' || $f === '..' || in_array($f, $skipName, true) || str_ends_with($f, '.tmp')) continue;
        $r = "$rel/$f";
        if (in_array($r, $skipPath, true)) continue;
        if (is_link("$abs/$f")) { fwrite(STDERR, "Refusing a link in the kit: $r\n"); exit(1); }
        if (is_dir("$abs/$f")) $walk($r);
        elseif (is_file("$abs/$f")) $files[$r] = "$abs/$f";
    }
};
foreach (['vendor', 'media', 'docs', 'edit'] as $d) {
    if (!is_dir("$repo/$d")) { fwrite(STDERR, "Missing from the site: $d/\n"); exit(1); }
    $walk($d);
}
foreach (array_keys($files) as $name) {
    if (preg_match('#(^|/)edit-dev(/|$)|^\.git|^\.github#', $name)) { fwrite(STDERR, "Refusing $name\n"); exit(1); }
}
$hash = Installer::codeHash($code);

// ---------------------------------------------------------------- the zip
if (!is_dir($out) && !mkdir($out, 0755, true)) { fwrite(STDERR, "Can't make $out\n"); exit(1); }
$now = time();
$n = 1;
while (is_file("$out/" . Installer::kitName($now, $n))) $n++;
$name = Installer::kitName($now, $n);
if (!preg_match(Installer::KIT_PATTERN, $name)) { fwrite(STDERR, "The kit name $name doesn't match what the installer deletes\n"); exit(1); }
$zipPath = "$out/$name";
$tmp = "$zipPath.part";
$h = fopen($tmp, 'wb');
$zip = new Zip($h);
sort($dirs);
foreach ($dirs as $d) $zip->addDir($d, $now);
ksort($files, SORT_STRING);
$bytes = 0;
foreach ($files as $zname => $src) { $zip->addFile($zname, $src); $bytes += filesize($src); }
$zip->addData('edit/install-code.hash', $hash . "\n", $now);
$size = $zip->finish();
fclose($h);
rename($tmp, $zipPath);

// ---------------------------------------------------------------- check it, reading every entry back
$entries = Zip::entries($zipPath);
$bad = [];
foreach ($files as $zname => $src) {
    $data = Zip::read($zipPath, $zname, $entries);
    if ($data === null || !hash_equals(hash_file('sha256', $src), hash('sha256', $data))) $bad[] = $zname;
}
$back = trim((string)Zip::read($zipPath, 'edit/install-code.hash', $entries));
if (!Installer::codeMatches($code, $back)) $bad[] = 'edit/install-code.hash';
if ($bad) { fwrite(STDERR, "The zip didn't check out: " . implode(', ', $bad) . "\n"); exit(1); }

// ---------------------------------------------------------------- the guide and the checklist
$day = Clock::local($now)->format('M j, Y');
copy(__DIR__ . '/launch-kit/guide.html', "$out/guide.html");
foreach (glob(__DIR__ . '/launch-kit/pictures/*.jpg') ?: [] as $pic) {
    @mkdir("$out/pictures", 0755);
    copy($pic, "$out/pictures/" . basename($pic));
}
$list = (string)file_get_contents(__DIR__ . '/launch-kit/checklist.html');
$list = str_replace(['%ZIP_NAME%', '%SETUP_CODE%', '%MADE%'], [htmlspecialchars($name), htmlspecialchars($code), htmlspecialchars($day)], $list);
file_put_contents("$out/launch-kit-checklist.html", $list);

// ---------------------------------------------------------------- report
$fmt = fn(int $b) => $b >= 1048576 ? sprintf('%.1f MB', $b / 1048576) : sprintf('%d KB', max(1, (int)round($b / 1024)));
foreach ($entries as $zname => $e) {
    if (str_ends_with($zname, '/')) continue;
    printf("  %-62s %9s\n", $zname, $fmt($e['size']));
}
$count = count(array_filter(array_keys($entries), fn($k) => !str_ends_with($k, '/')));
echo "\n$count files (" . $fmt($bytes) . "), zip " . $fmt($size) . ":\n  $zipPath\n";
echo "Also in $out:\n  guide.html (+ pictures/), launch-kit-checklist.html (has the setup code: it goes in John's Google Drive only)\n";
$head = trim((string)@shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse --short HEAD 2>/dev/null'));
$dirty = trim((string)@shell_exec('git -C ' . escapeshellarg($repo) . ' status --porcelain -- ' . implode(' ', array_map('escapeshellarg', array_merge($top, ['vendor', 'media', 'docs', 'edit']))) . ' 2>/dev/null'));
if ($head !== '') echo "Made from the working copy at $head" . ($dirty !== '' ? ', with ' . count(explode("\n", $dirty)) . ' files changed or new since that commit' : '') . ".\n";
echo "PDFs for Google Drive: node edit-dev/launch-kit/make-pdfs.js " . escapeshellarg($out) . "\n";
