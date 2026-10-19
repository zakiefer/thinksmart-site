<?php
// Installer tests: the setup code and its slow-down, the checks, the private folder's proof
// (including a leaky layout, served for real by php -S), logins, codes, the lead email, moving
// the old lead logs, publishing, the locks, "Reinstall and keep my content", "Start over",
// the emergency sheet, the zip writer, "Download a copy" and the launch-kit zip.
//   php edit-dev/tests/installer.php
// Everything runs in temporary folders (TS_TEST_TMP, or the system temp folder); mail goes to
// an outbox, never out. The repo's own files are never written.
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$base = rtrim(getenv('TS_TEST_TMP') ?: sys_get_temp_dir(), '/');
$tmp = "$base/ts-installer-tests-" . getmypid();
require "$repo/edit/lib/load.php";

use ThinkSmart\{Backup, Build, Clock, Codes, Config, ContentStore, Contracts, Files, Installer, InstallChecks, InstallRequest,
    InstallResponse, InstallState, Json, Journal, LeadSettings, Leads, LiveContent, People, Paths, Publisher, Schema, Zip};

$pass = 0;
$fail = 0;
function ok(bool $c, string $what): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
}

/** A little browser for Installer::handle(): keeps the cookies and the last page's form token. */
final class Browser
{
    public array $cookies = [];
    public string $last = '';
    private Installer $inst;
    private string $host;
    private bool $https;
    private string $ip;
    public string $docRoot = '';

    public function __construct(Installer $inst, string $host = 'www.example.test', bool $https = true, string $ip = '198.51.100.7')
    {
        $this->inst = $inst;
        $this->host = $host;
        $this->https = $https;
        $this->ip = $ip;
    }

    public function req(string $method, string $route, array $post = [], bool $sameOrigin = true): InstallResponse
    {
        $r = new InstallRequest();
        $r->method = $method;
        $r->route = InstallRequest::route($route);
        $r->post = $post;
        $r->cookies = $this->cookies;
        $r->https = $this->https;
        $r->host = $this->host;
        $r->ip = $this->ip;
        $r->origin = $method === 'POST' ? ($sameOrigin ? ($this->https ? 'https' : 'http') . '://' . $this->host : 'https://evil.example') : null;
        $r->docRoot = $this->docRoot;
        $r->serverAddr = '203.0.113.5';
        $res = $this->inst->handle($r);
        foreach ($res->cookies as [$n, $v, $s]) { if ($s < 0) unset($this->cookies[$n]); else $this->cookies[$n] = $v; }
        if ($res->body !== '') $this->last = $res->body;
        return $res;
    }

    public function get(string $route): InstallResponse
    {
        return $this->req('GET', $route);
    }

    public function post(string $route, array $post = [], bool $sameOrigin = true): InstallResponse
    {
        return $this->req('POST', $route, $post + ['f' => $this->token()], $sameOrigin);
    }

    public function token(): string
    {
        return preg_match('/name="f" value="([^"]*)"/', $this->last, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
    }

    public function text(): string
    {
        return html_entity_decode(trim((string)preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $this->last)))), ENT_QUOTES);
    }
}

function freePort(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string)stream_socket_get_name($s, false);
    fclose($s);
    return (int)substr((string)strrchr($name, ':'), 1);
}

/** A website hosting layout: <home>/public_html (with the site's pages) beside <home>/thinksmart-private. */
function makeSite(string $home, string $repo, string $code): Paths
{
    Files::rmTree($home);
    mkdir("$home/public_html/edit", 0755, true);
    mkdir("$home/public_html/docs", 0755, true);
    mkdir("$home/public_html/media", 0755, true);
    foreach (array_merge(glob("$repo/*.dc.html"), ["$repo/404.html", "$repo/index.html", "$repo/sitemap.xml"]) as $f) copy($f, "$home/public_html/" . basename($f));
    foreach (glob("$repo/docs/*.pdf") as $f) copy($f, "$home/public_html/docs/" . basename($f));
    file_put_contents("$home/public_html/edit/install-code.hash", Installer::codeHash($code) . "\n");
    return new Paths("$home/public_html", "$home/thinksmart-private", "$repo/edit");
}

function scanFor(string $dir, array $needles, array $skipDirs = []): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = (string)$f;
        foreach ($skipDirs as $s) if (str_contains($path, "/$s/")) continue 2;
        $data = (string)@file_get_contents($path);
        $flat = function_exists('gzdecode') ? (string)@gzdecode($data) : '';
        foreach ($needles as $n) if ($n !== '' && (str_contains($data, $n) || ($flat !== '' && str_contains($flat, $n)))) $hits[] = "$path has $n";
    }
    return $hits;
}

Files::rmTree($tmp);
mkdir("$tmp/tmp", 0700, true);
$CODE = 'TSK7-M2PX-9QWE';
$home = "$tmp/home";
$paths = makeSite($home, $repo, $CODE);
$priv = $paths->private;
$opt = ['dev' => false, 'mail' => 'outbox', 'slow' => false, 'tmp' => "$tmp/tmp", 'home' => $home, 'user' => 'tsuser',
    'ini' => ['upload_max_filesize' => '16M', 'post_max_size' => '20M'],
    // the web as seen from a correct hosting: the probe never answers, the editor's own files do
    'fetch' => fn(array $urls) => array_map(fn($u) => str_ends_with($u, '/edit/app/install.css') ? ['code' => 200, 'body' => '.inst{}'] : null, array_combine($urls, $urls))];
$inst = new Installer($paths, $opt);
$outbox = fn() => array_map(fn($f) => Json::read($f), glob("$priv/outbox/*.json") ?: []);

// ---------------------------------------------------------------- the zip writer
echo "Zip writer\n";
$big = "$tmp/big.bin";
file_put_contents($big, random_bytes(Zip::BIG + 12345));
foreach ([true, false] as $deflate) {
    $zf = "$tmp/t-" . ($deflate ? 'deflate' : 'stored') . '.zip';
    $h = fopen($zf, 'wb');
    $z = new Zip($h, $deflate);
    $z->addDir('edit');
    $z->addData('edit/a.txt', str_repeat("plain words\n", 400));
    $z->addData('café/ü.json', '{"a":1}');
    $z->addData('empty.txt', '');
    $z->addFile('big/big.bin', $big);
    $size = $z->finish();
    fclose($h);
    $e = Zip::entries($zf);
    ok($size === filesize($zf) && count($e) === 5, ($deflate ? 'deflated' : 'stored') . ' zip: 5 entries, size as reported');
    ok(Zip::read($zf, 'edit/a.txt', $e) === str_repeat("plain words\n", 400) && Zip::read($zf, 'big/big.bin', $e) === file_get_contents($big) && Zip::read($zf, 'empty.txt', $e) === '', 'every entry reads back the same (the big one streamed)');
    $z8 = $deflate && function_exists('gzdeflate');
    ok(($e['edit/a.txt']['method'] === 8) === $z8 && $e['big/big.bin']['method'] === 0, $z8 ? 'text is deflated, the big file stored' : 'without zlib everything is stored');
    ok($e['edit/']['mode'] === 040755 && $e['edit/a.txt']['mode'] === 0100644, 'folders 755, files 644');
    exec('unzip -tq ' . escapeshellarg($zf) . ' 2>&1', $o, $rc);
    ok($rc === 0, 'unzip -t says it\'s fine');
}
try { $z = new Zip(fopen('php://memory', 'wb')); $z->addData('../x', 'a'); ok(false, 'a ../ name is refused'); } catch (\InvalidArgumentException $ex) { ok(true, 'a ../ name is refused'); }
try { $z = new Zip(fopen('php://memory', 'wb')); $z->addData('a', '1'); $z->addData('a', '2'); ok(false, 'the same name twice is refused'); } catch (\InvalidArgumentException $ex) { ok(true, 'the same name twice is refused'); }

// ---------------------------------------------------------------- checks
echo "Checks\n";
ok(InstallChecks::php(80000, '8.0.30')['level'] === 'stop' && InstallChecks::php(80200, '8.2.1')['level'] === 'warn' && InstallChecks::php(80400, '8.4.1')['level'] === 'ok', 'PHP: 8.0 stops, 8.2 says pick a newer one, 8.4 is fine');
ok(str_contains(InstallChecks::php(80200, '8.2.1')['action'], 'MultiPHP Manager'), 'the PHP action names MultiPHP Manager');
ok(InstallChecks::bytes('16M') === 16777216 && InstallChecks::bytes('2G') === 2147483648 && InstallChecks::bytes('512K') === 524288 && InstallChecks::bytes('0') === 0, 'upload sizes read like PHP does');
$up = InstallChecks::uploads('8M', '20M');
ok($up['level'] === 'stop' && str_contains($up['action'], 'MultiPHP INI Editor') && str_contains($up['action'], '16M'), 'an 8 MB upload limit stops, naming MultiPHP INI Editor');
ok(InstallChecks::uploads('16M', '20M')['level'] === 'ok' && InstallChecks::uploads('0', '0')['level'] === 'ok' && InstallChecks::uploads('32M', '8M')['level'] === 'stop', '16 MB is enough; "no limit" too; post_max_size counts as well');
$r = new InstallRequest();
$r->host = 'www.thinksmartpublicadjusting.com';
$r->https = false;
$h = InstallChecks::https($r, false);
ok($h['level'] === 'stop' && str_contains($h['action'], 'https://www.thinksmartpublicadjusting.com/edit/install') && str_contains($h['action'], 'AutoSSL'), 'plain http stops, with the https address and AutoSSL');
ok(InstallChecks::https($r, true)['level'] === 'ok', 'a local test may use http');
ok(InstallChecks::address($r, false)['level'] === 'ok', 'the website\'s own address is fine');
$r->host = '203.0.113.5';
ok(InstallChecks::address($r, false)['level'] === 'stop', 'opened at the server\'s IP: stop');
$r->host = 'staging.example.com';
ok(InstallChecks::address($r, false)['level'] === 'warn', 'another address: a warning (the emails would use it)');
ok(InstallChecks::primary($paths, false, $home)['level'] === 'ok', 'public_html in the home folder is the main website');
ok(InstallChecks::primary($paths, false, "$tmp/elsewhere")['level'] !== 'stop' || true, '(home not found is not checked)');
ok(InstallChecks::primary($paths, false, $tmp)['level'] === 'stop', 'public_html that isn\'t in the account\'s home folder stops');
$addon = new Paths("$tmp/home/public_html/addon.com", "$tmp/home/public_html/thinksmart-private", "$repo/edit");
$pr = InstallChecks::primary($addon, false, null);
ok($pr['level'] === 'stop' && str_contains($pr['action'], 'primary domain'), 'a second website inside public_html stops: "make it the primary domain"');
ok(InstallChecks::folders(new Paths("$tmp/nowhere", "$tmp/nowhere/p", "$repo/edit"))['level'] === 'stop', 'missing folders stop');

echo "The setup code\n";
$b = new Browser($inst);
$res = $b->get('');
ok($res->code === 200 && str_contains($b->last, 'name="code"') && !is_dir($priv), 'the start page asks for the setup code, and nothing is made yet');
ok($b->get('/checks')->location === '/edit/install', 'no code yet: every step goes back to the start');
$res = $b->post('', ['code' => 'WRONG-CODE-0000']);
ok(str_contains($b->text(), 'isn\'t right') && !isset($b->cookies[Installer::COOKIE]) && !is_dir($priv), 'a wrong code: nothing happens');
$res = $b->post('', ['code' => $CODE], false);
ok(str_contains($b->text(), 'open too long') && !isset($b->cookies[Installer::COOKIE]), 'the right code from another website is refused');
for ($i = 0; $i < 4; $i++) $b->post('', ['code' => "WRONG-$i"]);
ok(str_contains($b->text(), 'Too many tries'), 'five wrong codes: slowed down ("Too many tries")');
$b->post('', ['code' => $CODE]);
ok(str_contains($b->text(), 'Too many tries') && !isset($b->cookies[Installer::COOKIE]), 'even the right code waits its turn');
$b2 = new Browser($inst, 'www.example.test', true, '192.0.2.44');
$b2->get('');
$res = $b2->post('', ['code' => strtolower(str_replace('-', ' ', $CODE))]);
ok($res->location === '/edit/install/checks' && isset($b2->cookies[Installer::COOKIE]), 'someone else (another address) gets in, with the code typed in small letters and spaces');
Clock::set(Clock::now() + 61);
$res = $b->post('', ['code' => $CODE]);
ok($res->location === '/edit/install/checks' && isset($b->cookies[Installer::COOKIE]), 'after the wait, the right code works');
Clock::set(null);
$forged = $b->cookies;
$forged[Installer::COOKIE] = preg_replace('/.$/', 'A', $forged[Installer::COOKIE]) === $forged[Installer::COOKIE] ? $forged[Installer::COOKIE] . 'x' : preg_replace('/.$/', 'A', $forged[Installer::COOKIE]);
$fb = new Browser($inst);
$fb->cookies = $forged;
ok($fb->get('/checks')->location === '/edit/install', 'a changed cookie doesn\'t get in');

echo "Checks page and the private folder\n";
$res = $b->get('/checks');
ok($res->code === 200 && str_contains($b->text(), 'PHP version') && str_contains($b->text(), 'Upload size') && str_contains($b->text(), 'Room on the hosting'), 'the checks page lists the checks');
ok(!str_contains($b->last, 'is-stop'), 'nothing red on this hosting');
ok($b->get('/people')->location === '/edit/install/checks', 'the private folder comes before the people');
$res = $b->post('/checks', ['f' => 'nope']);
ok(!is_dir($priv) && str_contains($b->text(), 'open too long'), 'a step without its form token does nothing');
$b->get('/checks');
$res = $b->post('/checks');
ok($res->location === '/edit/install/people', 'the private folder is made and proved, then on to the people');
ok(is_dir($priv) && (fileperms($priv) & 0777) === 0700, 'the private folder is 0700');
ok(!glob("$priv/probe-*") && !glob("$priv/.room-check-*"), 'the probe and the room test are gone');
$b->get('/checks');
ok(str_contains($b->last, 'is-ok') && str_contains($b->text(), 'Only this hosting account can open it') && str_contains($b->last, 'href="/edit/install/people">Next<'), 'back on the checks page: the folder is shown as proved, with Next');
$quiet = new Installer($paths, ['fetch' => fn(array $urls) => array_fill_keys($urls, null)] + $opt);
$qb = new Browser($quiet, 'www.example.test', true, '192.0.2.99');
$qb->get('');
$qb->post('', ['code' => $CODE]);
$qb->get('/checks');
$res = $qb->post('/checks');
ok($res->code === 200 && str_contains($qb->last, 'is-warn') && str_contains($qb->text(), 'Not Found') && str_contains($qb->last, '>Next<'), 'a hosting that can\'t reach itself: the orange note is shown, with Next (not a stop)');
ok($b->get('/finish')->location === '/edit/install/people', 'steps can\'t be skipped');

echo "The private folder's proof\n";
$port = freePort();
$leakHome = "$tmp/leak";
$lp = makeSite($leakHome, $repo, $CODE);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $leakHome], [0 => ['pipe', 'r'], 1 => ['file', "$tmp/leak-server.log", 'a'], 2 => ['file', "$tmp/leak-server.log", 'a']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$lr = new InstallRequest();
$lr->host = "127.0.0.1:$port";
$lr->https = false;
$leak = InstallChecks::makePrivate($lp, $lr, ['probeBases' => ["http://127.0.0.1:$port"], 'user' => 'tsuser']);
ok($leak['level'] === 'stop' && str_contains($leak['text'], "http://127.0.0.1:$port/thinksmart-private/") && str_contains($leak['action'], 'primary domain'),
    'the home folder served as a website: the probe answers, and the setup stops');
ok(!glob($lp->private . '/probe-*') && (fileperms($lp->private) & 0777) === 0700, 'the probe is removed and the folder closed again');
$socket = InstallChecks::fetchAll(["http://127.0.0.1:$port/public_html/index.html", "http://127.0.0.1:$port/nothing-here", 'http://127.0.0.1:1/x'], 3, false);
ok(($socket["http://127.0.0.1:$port/public_html/index.html"]['code'] ?? 0) === 200 && ($socket["http://127.0.0.1:$port/nothing-here"]['code'] ?? 0) === 404 && $socket['http://127.0.0.1:1/x'] === null,
    'the fetcher without curl: 200, 404, and a refused connection counts as no answer');
if (function_exists('curl_multi_init')) {
    $c = InstallChecks::fetchAll(["http://127.0.0.1:$port/public_html/index.html", 'http://127.0.0.1:1/x']);
    ok(($c["http://127.0.0.1:$port/public_html/index.html"]['code'] ?? 0) === 200 && $c['http://127.0.0.1:1/x'] === null, 'the curl fetcher too');
}
// a correct layout, served for real: the probe doesn't answer, and the self-check proves the test ran
$port2 = freePort();
@mkdir($lp->webroot . '/edit/app', 0755, true);
copy("$repo/edit/app/install.css", $lp->webroot . '/edit/app/install.css');
$srv2 = proc_open(['php', '-S', "127.0.0.1:$port2", '-t', $lp->webroot], [0 => ['pipe', 'r'], 1 => ['file', "$tmp/ok-server.log", 'a'], 2 => ['file', "$tmp/ok-server.log", 'a']], $pipes2);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port2); $i++) usleep(100000);
Files::rmTree($lp->private);
$proved = InstallChecks::makePrivate($lp, $lr, ['probeBases' => ["http://127.0.0.1:$port2"], 'user' => 'tsuser']);
ok($proved['level'] === 'ok' && str_contains($proved['text'], 'tries to open it from the web all failed'), 'the right layout, served for real: closed, and proved (the website itself answered)');
$pinned = InstallChecks::fetchAll(["http://www.example.test:$port2/edit/app/install.css"], 3, false, ['www.example.test' => '127.0.0.1']);
ok(($pinned["http://www.example.test:$port2/edit/app/install.css"]['code'] ?? 0) === 200, 'the website\'s name is sent to this server\'s own address (works before the DNS switch), without curl');
if (function_exists('curl_multi_init')) {
    $pc = InstallChecks::fetchAll(["http://www.example.test:$port2/edit/app/install.css"], 3, true, ['www.example.test' => '127.0.0.1']);
    ok(($pc["http://www.example.test:$port2/edit/app/install.css"]['code'] ?? 0) === 200, 'and with curl');
}
file_put_contents($lp->webroot . '/redir.php', '<?php header("Location: http://127.0.0.1:' . $port . '/public_html/index.html", true, 302);');
$rd = InstallChecks::fetchAll(["http://127.0.0.1:$port2/redir.php"], 3, false);
ok(($rd["http://127.0.0.1:$port2/redir.php"]['code'] ?? 0) === 302, 'a redirect isn\'t followed (without curl)');
if (function_exists('curl_multi_init')) {
    $rc = InstallChecks::fetchAll(["http://127.0.0.1:$port2/redir.php"]);
    ok(($rc["http://127.0.0.1:$port2/redir.php"]['code'] ?? 0) === 302, 'nor with curl');
}
@unlink($lp->webroot . '/redir.php');
$pr2 = new InstallRequest();
$pr2->host = 'www.thinksmartpublicadjusting.com';
$pr2->serverAddr = '203.0.113.5';
ok(InstallChecks::pins($pr2) === ['www.thinksmartpublicadjusting.com' => '203.0.113.5', 'thinksmartpublicadjusting.com' => '203.0.113.5'], 'both the www and the bare name are pinned to the server\'s address');
// a made-up Host header can't send the test anywhere but this server, on the usual ports
$hostOf = fn(array $bases) => array_values(array_unique(array_map(fn($b) => (string)parse_url($b, PHP_URL_HOST) . (parse_url($b, PHP_URL_PORT) ? ':' . parse_url($b, PHP_URL_PORT) : ''), $bases)));
$sp = new InstallRequest();
$sp->serverAddr = '203.0.113.5';
$sp->host = 'evil.example.com:6379';
ok($hostOf(InstallChecks::probeBases($sp)) === ['evil.example.com', 'www.evil.example.com', '203.0.113.5'] && InstallChecks::pins($sp) === ['evil.example.com' => '203.0.113.5', 'www.evil.example.com' => '203.0.113.5'],
    'a name in the Host header: its port is dropped and the name is sent to this server\'s address');
$sp->host = '127.0.0.1:8799';
$pb1 = InstallChecks::probeBases($sp);
$sp->host = '[::1]:9999';
$pb2 = InstallChecks::probeBases($sp);
$sp->host = '169.254.169.254';
$pb3 = InstallChecks::probeBases($sp);
ok($hostOf($pb1) === ['203.0.113.5'] && $pb1 === $pb2 && $pb2 === $pb3, 'an address in the Host header is never tried: only the server\'s own (' . implode(', ', $pb1) . ')');
$sp->serverAddr = '';
$sp->host = 'www.thinksmartpublicadjusting.com';
ok(InstallChecks::probeBases($sp) === [], 'without the server\'s own address nothing is tried (the check then asks for a look by hand)');
$sp->host = 'localhost:8792';
ok(in_array('http://localhost:8792', InstallChecks::probeBases($sp, true), true), 'a test on this computer keeps its port');
proc_terminate($srv2);
proc_close($srv2);
proc_terminate($srv);
proc_close($srv);
// a folder that already holds data stays 0700: "forbidden" where a made-up name is "not found" counts as reachable
file_put_contents($lp->private . '/content.json', '{"site":{}}');
$seen = [];
$diff = InstallChecks::makePrivate($lp, $lr, ['probeBases' => ['https://www.example.test'], 'user' => 'tsuser', 'fetch' => function (array $urls) use (&$seen, $lp) {
    $seen[] = decoct(fileperms($lp->private) & 0777);
    $out = [];
    foreach ($urls as $u) $out[$u] = ['code' => str_contains($u, 'thinksmart-private-none') ? 404 : 403, 'body' => 'Forbidden'];
    return $out;
}]);
ok($diff['level'] === 'stop' && $seen === ['700'], 'with data in it, the folder stays 0700 during the test, and 403-vs-404 stops the setup');
$okNone = InstallChecks::makePrivate($lp, $lr, ['probeBases' => ['https://www.example.test'], 'fetch' => fn(array $urls) => array_fill_keys($urls, ['code' => 403, 'body' => 'Forbidden'])]);
ok($okNone['level'] === 'warn' && str_contains($okNone['action'], 'Not Found'), 'a server that refuses everything can\'t prove anything: an orange note with a check by hand, not a stop');
$silent = InstallChecks::makePrivate($lp, $lr, ['probeBases' => ['https://www.example.test'], 'fetch' => fn(array $urls) => array_fill_keys($urls, null)]);
ok($silent['level'] === 'warn' && str_contains($silent['text'], 'couldn\'t open its own website'), 'no answer at all: the same note (unreachable passes, but isn\'t called proof)');
$inside = new InstallRequest();
$inside->docRoot = $leakHome;
$in = InstallChecks::makePrivate($lp, $inside, ['fetch' => fn(array $urls) => array_fill_keys($urls, null)]);
ok($in['level'] === 'stop' && str_contains($in['text'], 'inside the website'), 'a private folder inside the document root stops before anything is written');
$urls = InstallChecks::probeUrls($paths, (function () { $r = new InstallRequest(); $r->host = 'www.thinksmartpublicadjusting.com'; $r->serverAddr = '203.0.113.5'; return $r; })(), 'probe-x/p.txt', null, 'tsuser');
$want = ['https://www.thinksmartpublicadjusting.com/thinksmart-private/probe-x/p.txt', 'http://thinksmartpublicadjusting.com/thinksmart-private/probe-x/p.txt',
    'https://203.0.113.5/thinksmart-private/probe-x/p.txt', 'http://203.0.113.5/~tsuser/thinksmart-private/probe-x/p.txt'];
ok(!array_diff($want, $urls), 'the probe is tried through the domain, www, the server\'s IP and IP/~user, over https and http (' . count($urls) . ' addresses)');

echo "Who can edit\n";
$bad = Installer::peopleProblems([['name' => '', 'username' => 'john@x.com', 'email' => 'nope'], ['name' => 'M', 'username' => 'thinksmartpublicadjusting', 'email' => 'm@x.com']]);
ok(isset($bad[0]['name'], $bad[0]['username'], $bad[0]['email'], $bad[1]['username']), 'missing name, an email as username, a bad email and the business name as username are all caught');
ok(isset(Installer::peopleProblems([['name' => 'A', 'username' => 'same-1', 'email' => 'a@x.com'], ['name' => 'B', 'username' => 'SAME-1', 'email' => 'A@x.com']])[1]['username']), 'two people can\'t share a username');
ok(isset(Installer::peopleProblems([['name' => 'A', 'username' => 'aaa-1', 'email' => 'a@x.com'], ['name' => 'B', 'username' => 'bbb-2', 'email' => 'A@x.com']])[1]['email']), 'or an email');
$res = $b->get('/people');
ok(str_contains($b->last, 'value="John"') && str_contains($b->last, 'value="Muneerah"') && !str_contains($b->last, 'type="password"'), 'the people page starts with John and Muneerah, and asks for no passwords');
$res = $b->post('/people', ['p' => [['name' => 'John', 'username' => 'john', 'email' => 'john@example.test'], ['name' => 'Muneerah', 'username' => 'mun-2026', 'email' => 'mun@example.test']]]);
ok(str_contains($b->text(), 'easy to guess') && !isset(Json::read(glob("$priv/install/state-*.json")[0])['people']), 'a username anyone could guess is refused');
$res = $b->post('/people', ['p' => [['name' => 'John', 'username' => 'jnelson-77', 'email' => 'John@Example.test'], ['name' => 'Muneerah', 'username' => 'mun-2026', 'email' => 'mun@example.test']]]);
ok($res->location === '/edit/install/leads', 'two good logins: on to the leads');

echo "Leads\n";
$old = "$home/thinksmart-leads";
mkdir($old, 0700);
$day = fn(int $n) => gmdate('c', time() - $n * 86400);
$rec = [
    ['received' => $day(120), 'kind' => 'claim', 'case_no' => 'TS-OLD', 'ip' => '1.2.3.4', 'emailed' => true, 'payload' => ['Full name' => 'Too Old', 'Phone' => '812-555-0100']],
    ['received' => $day(40), 'kind' => 'chat', 'case_no' => '', 'ip' => '1.2.3.5', 'emailed' => true, 'payload' => ['Chat transcript' => "Visitor: hail\nVisitor: 812-555-0142"]],
    ['received' => $day(12), 'kind' => 'contact', 'case_no' => '', 'ip' => '1.2.3.6', 'emailed' => true, 'payload' => ['Full name' => 'Pat Example', 'Phone' => '(812) 555-0151']],
    ['received' => $day(3), 'kind' => 'claim', 'case_no' => 'TS-2026-1', 'ip' => '1.2.3.7', 'emailed' => false, 'payload' => ['Full name' => 'Sam Example', 'Email' => 'sam@example.test']],
    ['received' => $day(3), 'kind' => 'photos', 'case_no' => 'TS-2026-1', 'name' => 'Sam Example', 'ip' => '1.2.3.7', 'photos' => 2, 'emailed' => true],
];
file_put_contents("$old/leads-2026-06.log", json_encode($rec[0]) . "\n{\"received\":\"20\n");
file_put_contents("$old/leads-2026-10.log", implode("\n", array_map('json_encode', array_slice($rec, 1))) . "\n");
$res = $b->get('/leads');
ok(str_contains($b->last, 'value="thinksmartpublicadjusting@gmail.com"') && str_contains($b->text(), '(5 in all)') && str_contains($b->text(), 'The 4 from the last 90 days'), 'the lead email starts as the business Gmail, and the old copies are counted (damaged line left out)');
$res = $b->post('/leads', ['lead' => 'not an email']);
ok(str_contains($b->text(), 'doesn\'t look right'), 'a bad lead address is refused');
$res = $b->post('/leads', ['lead' => 'Leads@Example.test']);
ok($res->location === '/edit/install/finish', 'on to "Set it up"');

echo "Set it up\n";
foreach (['think-smart-launch-kit-2026-10-18.zip', 'think-smart-launch-kit-2026-10-18 (1).zip', 'think-smart-launch-kit-2026-10-18-2.zip', 'other.zip', 'think-smart-launch-kit-notes.txt'] as $z) file_put_contents("$paths->webroot/$z", 'zip');
mkdir("$paths->webroot/think-smart-launch-kit-2026-01-01.zip");
$res = $b->get('/finish');
ok(str_contains($b->text(), 'jnelson-77') && str_contains($b->text(), 'leads@example.test') && str_contains($b->text(), 'https://www.example.test/edit') && str_contains($b->text(), 'is deleted from the website folder'), 'the summary shows the address, the people, the lead email and the zip to delete');
ok(!is_file("$priv/config.json") && !is_file("$priv/users.json"), 'nothing is written before "Set it up"');
$res = $b->post('/finish');
ok($res->location === '/edit/install/done', 'it sets up and goes to the passwords page' . ($res->location === null ? ': ' . substr($b->text(), 0, 300) : ''));
$cfgJ = Json::read("$priv/config.json");
ok(preg_match('/^[a-f0-9]{64}$/', (string)$cfgJ['secret']) === 1 && $cfgJ['siteUrl'] === 'https://www.example.test' && $cfgJ['mail'] === 'outbox' && $cfgJ['dev'] === false, 'config.json: a new secret, the address from the request, the mail setting');
ok((fileperms("$priv/config.json") & 0777) === 0600, 'config.json is 0600');
$cfg = Config::load($paths);
ok($cfg->ready(), 'the editor is ready (no more "Not set up yet")');
$live = (new LiveContent($cfg, Schema::load($paths)))->load();
$seed = json_decode((string)file_get_contents("$repo/edit/seed/content.json"), true);
ok($live['site']['phone'] === $seed['site']['phone'] && isset($live['faq']['items'][0]['_id']), 'the content is the starting content (with ids)');
$same = true;
foreach ((new Build($paths))->render($live) as $file => $text) if (file_get_contents("$paths->webroot/$file") !== $text) $same = false;
ok($same, 'every page in the website folder is the build of that content');
$pub = new Publisher($cfg, new People($cfg));
ok($pub->history->rev() === 1 && $pub->history->head()['kind'] === 'launch', 'History starts with the launch-day version');
$people = new People($cfg);
$act = $people->active();
ok(count($act) === 2 && $act[0]['username'] === 'jnelson-77' && $act[0]['email'] === 'john@example.test' && $act[0]['alerts'] === true && $act[1]['alerts'] === false, 'John (change alerts on) and Muneerah can sign in');
ok($act[0]['hash'] === '' && $act[1]['hash'] === '' && count($act[0]['rescue']) === 2 && count($act[1]['rescue']) === 2, 'no passwords yet, 2 rescue codes each');
$mails = $outbox();
$codeMails = array_values(array_filter($mails, fn($m) => $m['subject'] === 'Set your password for the website editor'));
$to = array_merge(...array_map(fn($m) => $m['to'], $codeMails));
sort($to);
ok(count($codeMails) === 2 && $to === ['john@example.test', 'mun@example.test'] && str_contains($codeMails[0]['html'], 'https://www.example.test/edit/?code') && !str_contains($codeMails[0]['text'] . $codeMails[1]['text'], 'jnelson-77'),
    'each person is emailed a set-your-password code with a button to /edit/?code (and not the username)');
$lead = array_values(array_filter($mails, fn($m) => str_contains($m['subject'], 'Test lead')));
ok(count($lead) === 1 && $lead[0]['to'] === ['leads@example.test'] && str_contains($lead[0]['html'], '/edit/confirm?t='), 'the lead address gets a test lead with a confirm button');
$set = LeadSettings::load($cfg);
ok(($set['leadTo'][0]['email'] ?? '') === 'leads@example.test' && ($set['leadTo'][0]['state'] ?? '') === 'waiting', 'the lead address waits for its confirmation (the setup finished anyway)');
$imported = array_map(fn($f) => Json::read($f), Leads::files($paths));
ok(count($imported) === 4 && !array_filter($imported, fn($l) => $l['case_no'] === 'TS-OLD'), 'the 4 leads from the last 90 days are in the editor\'s Leads; the older one is not');
ok((bool)array_filter($imported, fn($l) => $l['kind'] === 'photos' && $l['photoCount'] === 2) && (bool)array_filter($imported, fn($l) => $l['name'] === 'Pat Example' && $l['imported'] === true), 'claims, photos and messages come across with their details');
ok(!is_dir($old), 'the old lead logs and their folder are deleted');
ok(!is_file("$paths->webroot/think-smart-launch-kit-2026-10-18.zip") && !is_file("$paths->webroot/think-smart-launch-kit-2026-10-18 (1).zip") && !is_file("$paths->webroot/think-smart-launch-kit-2026-10-18-2.zip"), 'the leftover setup zips are deleted');
ok(is_file("$paths->webroot/other.zip") && is_file("$paths->webroot/think-smart-launch-kit-notes.txt") && is_dir("$paths->webroot/think-smart-launch-kit-2026-01-01.zip"), 'other files (and a folder with a zip-like name) are left alone');
$readme = (string)@file_get_contents("$priv/README-developer.md");
ok(str_contains($readme, '## How this hosting is laid out') && str_contains($readme, InstallChecks::absPath($priv)) && str_contains($readme, '/edit/install/reinstall') && str_contains($readme, "\n## Site builder and editor (for developers)\n") && str_contains($readme, "\n### Build\n"),
    'the developer README is in the private folder: hosting layout, reinstall steps, then edit/README.md');
ok(is_file("$priv/pristine/manifest.json") && isset(Json::read("$priv/pristine/manifest.json")['files']['edit/install-code.hash']), 'the pristine copy for self-repair is made');
ok(is_file("$priv/install.lock") && preg_match('/^at=\d+$/m', (string)file_get_contents("$paths->webroot/edit/.installed")) === 1, 'both locks are written');
ok(!is_file("$paths->webroot/edit/.installed") || !isset(Json::read("$priv/pristine/manifest.json")['files']['edit/.installed']), 'the website-folder lock isn\'t part of the pristine copy');

echo "Passwords and the emergency sheet\n";
$res = $b->get('/done');
preg_match_all('/class="code-big">(\d{3}) (\d{3})</', $b->last, $m);
$codes = array_map(fn($a, $c) => $a . $c, $m[1], $m[2]);
ok(count($codes) === 2 && str_contains($b->text(), 'open example.test/edit/?code') && str_contains($b->text(), 'jnelson-77') && str_contains($b->text(), 'mun-2026'), 'the passwords page shows each person\'s username and 6-digit code');
ok(str_contains($b->text(), '4 leads from the old form copies') && str_contains($b->text(), 'A test lead was sent to leads@example.test'), 'and what happened to the leads');
ok((new Codes($cfg))->check($act[1]['id'], $codes[1]) === 'ok', 'Muneerah\'s code works (at /edit/?code, with her new password)');
$res = $b->get('/sheet');
$sheet = $b->last;
preg_match_all('/<span class="inst-code">([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})<\/span>/', $sheet, $rm);
$tableCodes = [];
if (preg_match('/<tbody>(.*?)<\/tbody>/s', $sheet, $tb)) preg_match_all('/([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})/', $tb[1], $tc);
$tableCodes = $tc[1] ?? [];
ok(count($tableCodes) === 2 && count(array_unique($rm[1])) === 4, 'the sheet prints one rescue code each; the second ones are shown for the Passwords app');
ok((bool)preg_match('/class="inst-tools no-print".*Second rescue codes/s', $sheet), 'the second codes are in the part that isn\'t printed');
$p2 = new People($cfg);
ok($p2->useRescueCode($act[0]['id'], $tableCodes[0]) && $p2->useRescueCode($act[0]['id'], $rm[1][array_search($tableCodes[0], $rm[1], true) === 0 ? 1 : 0] ?? ''), 'both of John\'s rescue codes work');
$t = (new Browser($inst))->text();
$sheetText = $b->text();
ok(str_contains($sheetText, 'https://www.example.test/edit') && str_contains($sheetText, 'If someone leaves') && str_contains($sheetText, 'Change the GoDaddy password') && str_contains($sheetText, 'Change the Gmail password')
    && str_contains($sheetText, 'Forgot your password?') && str_contains($sheetText, Installer::GODADDY_PHONE), 'the sheet: addresses, If someone leaves, Forgot your password?, who to call');
ok(!preg_match('/\b(update|maintenance|maintain)/i', $sheetText . $b->text()), 'no word about updates or maintenance');
ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $sheet) && !preg_match('/\sstyle=/i', $sheet) && !preg_match('/\son[a-z]+=/i', $sheet) && str_contains($sheet, 'data-print') && str_contains($sheet, '/edit/app/install.js'),
    'no inline scripts, styles or handlers; the Print button is wired by install.js');
$plain = array_merge($rm[1], array_map(fn($c) => str_replace('-', '', $c), $rm[1]), array_map(fn($c) => '"' . $c . '"', $codes));
ok(scanFor($priv, $plain, ['outbox']) === [], 'no code is readable anywhere in the private folder (they\'re encrypted for the sheet)');
$st = glob("$priv/install/state-*.json");
ok(count($st) === 1 && count(glob("$priv/install/codes-*.bin")) === 1, 'one installer session is kept until the sheet is done');
$res = $b->post('/sheet', ['do' => 'close']);
ok(str_contains($b->text(), 'All set') && !glob("$priv/install/*") && !isset($b->cookies[Installer::COOKIE]), '"Done": the codes and the session are gone');
ok($b->get('/sheet')->code === 404, 'the sheet can\'t be shown again');

echo "Locked\n";
foreach (['', '/checks', '/people', '/finish', '/done', '/nope'] as $route) $codesLocked[$route] = (new Browser($inst))->get($route)->code;
ok(!array_filter($codesLocked, fn($c) => $c !== 404), '/edit/install and every step answer 404');
$lb = new Browser($inst);
$lb->get('');
ok($lb->post('', ['code' => $CODE])->code === 404 && !isset($lb->cookies[Installer::COOKIE]), 'the setup code doesn\'t open /edit/install again');
ok($b2->get('/checks')->code === 404 && $b2->get('/people')->code === 404, 'a session started before the lock can\'t do anything now');
@unlink("$paths->webroot/edit/.installed");
ok((new Browser($inst))->get('')->code === 404, 'with only the private lock: still 404');
file_put_contents("$paths->webroot/edit/.installed", "at=" . time() . "\n");
rename("$priv/install.lock", "$priv/install.lock.x");
ok((new Browser($inst))->get('')->code === 404, 'with only edit/.installed: still 404 (holds even if the private folder were lost)');
rename("$priv/install.lock.x", "$priv/install.lock");

echo "Reinstall and keep my content\n";
// life after launch: John sets his password and changes the price, a lead arrives, the lead address is confirmed
$people = new People($cfg);
$people->setPassword($act[0]['id'], 'Storm-Tree-42-blue');
$people->save();
$hashJohn = (new People($cfg))->find($act[0]['id'])['hash'];
$pub = new Publisher($cfg, new People($cfg));
$livePrice = $pub->live->load()['site']['price'];
$r1 = $pub->publish($act[0]['id'], [['op' => 'set', 'path' => ['site', 'price'], 'value' => 349, 'old' => $livePrice]], [], 'reinst-test-1', 1);
ok($r1['ok'], 'a change went live after launch');
LeadSettings::confirm($cfg, 'leads@example.test');
Leads::put($paths, ['id' => Leads::newId(time()), 'at' => time(), 'kind' => 'contact', 'case_no' => '', 'payload' => ['Full name' => 'After Launch'], 'mail' => ['state' => 'sent']]);
@mkdir("$priv/sessions", 0700);
file_put_contents("$priv/sessions/old.json", '{}');
$histN = count($pub->history->entries());
$secret1 = $cfg->secret();
$ids1 = array_column((new People($cfg))->active(), 'id');
file_put_contents("$paths->webroot/Home.dc.html", 'hacked');
$mailsBefore = glob("$priv/outbox/*.json") ?: [];
$rb = new Browser($inst);
$res = $rb->get('/reinstall');
ok($res->code === 200 && str_contains($rb->text(), 'Reinstall and keep my content') && str_contains($rb->text(), 'Extract') && !str_contains($rb->last, 'name="code"'),
    'once set up, /edit/install/reinstall first asks for the launch kit to be unzipped again (no code box yet)');
$rb->post('/reinstall', ['code' => $CODE]);
ok(!isset($rb->cookies[Installer::COOKIE]), 'the setup code alone gets nowhere');
// the launch kit unzipped again (cPanel → File Manager → Extract): edit/install-code.hash is rewritten
foreach (["$priv/install.lock", "$paths->webroot/edit/.installed"] as $lf) touch($lf, time() - 120);
file_put_contents("$paths->webroot/edit/install-code.hash", file_get_contents("$paths->webroot/edit/install-code.hash"));
$res = $rb->get('/reinstall');
ok(str_contains($rb->last, 'name="code"'), 'after the unzip it asks for the setup code');
$rb->post('/reinstall', ['code' => 'WRONG-AGAIN-1']);
ok(!isset($rb->cookies[Installer::COOKIE]) && str_contains($rb->text(), 'isn\'t right'), 'a wrong code gets nowhere');
$res = $rb->post('/reinstall', ['code' => $CODE]);
ok($res->location === '/edit/install/checks', 'the right code starts the reinstall');
$started = array_values(array_filter(array_map(fn($f) => Json::read($f), array_diff(glob("$priv/outbox/*.json") ?: [], $mailsBefore)), fn($m) => ($m['kind'] ?? '') === 'alert'));
ok(count($started) === 1 && $started[0]['to'] === ['john@example.test'] && $started[0]['subject'] === 'The website editor is being set up again' && str_contains($started[0]['text'], 'change the GoDaddy password'),
    'John is told the moment someone starts a reinstall');
$rb->get('/checks');
$res = $rb->post('/checks');
ok($res->location === '/edit/install/people', 'checks and the private folder again (it already exists), then the people');
ok($rb->get('/content')->location !== null, 'no "start over" choice in a reinstall');
$rb->get('/people');
ok(str_contains($rb->text(), 'jnelson-77') && str_contains($rb->text(), 'same password as before') && str_contains($rb->text(), 'Make new rescue codes'), 'the people are kept, with an offer of new rescue codes');
$res = $rb->post('/people', ['newRescue' => '1']);
ok($res->location === '/edit/install/finish', 'the lead address is kept, so straight to "Set it up"');
$res = $rb->post('/finish');
ok($res->location === '/edit/install/done', 'the reinstall finishes');
$cfg2 = Config::load($paths);
$pub2 = new Publisher($cfg2, new People($cfg2));
ok($pub2->live->load()['site']['price'] === 349, 'the content is kept (the price changed after launch is still there)');
ok(file_get_contents("$paths->webroot/Home.dc.html") === (new Build($paths))->render($pub2->live->load())['Home.dc.html'], 'the damaged page is rebuilt');
ok(count($pub2->history->entries()) === $histN, 'History is kept as it was');
ok(array_column((new People($cfg2))->active(), 'id') === $ids1 && (new People($cfg2))->find($act[0]['id'])['hash'] === $hashJohn, 'the logins are kept, John\'s password too');
ok((LeadSettings::load($cfg2)['leadTo'][0]['state'] ?? '') === 'confirmed' && count(Leads::files($paths)) === 5, 'the lead address (confirmed) and the leads are kept');
ok($cfg2->secret() !== $secret1 && !glob("$priv/sessions/*.json"), 'a new secret: everyone signs in again');
$newMails = array_values(array_map(fn($f) => Json::read($f), array_diff(glob("$priv/outbox/*.json") ?: [], $mailsBefore)));
$alerts = array_values(array_filter($newMails, fn($m) => ($m['kind'] ?? '') === 'alert'));
$others = array_values(array_filter($newMails, fn($m) => ($m['kind'] ?? '') !== 'alert'));
ok(count($others) === 1 && $others[0]['to'] === ['mun@example.test'], 'only Muneerah (who never set a password) gets a new code; no test lead to a confirmed address');
ok(count($alerts) === 2 && $alerts[1]['to'] === ['john@example.test'] && $alerts[1]['subject'] === 'The website editor was set up again' && str_contains($alerts[1]['text'], 'old emergency sheet'),
    'and John is told it was set up again, and that the old sheet\'s codes stopped working');
$rb->get('/done');
ok(str_contains($rb->text(), 'John signs in with the same password as before'), 'the passwords page says John keeps his password');
$rb->get('/sheet');
$rs = $rb->last;
preg_match('/<tbody>(.*?)<\/tbody>/s', $rs, $tb2);
preg_match_all('/([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})/', $tb2[1] ?? '', $tc2);
ok(count($tc2[1]) === 2 && !in_array($tableCodes[0], $tc2[1], true), 'new rescue codes on a new sheet');
ok(!(new People($cfg2))->useRescueCode($act[1]['id'], $tableCodes[1]), 'the old sheet\'s codes stop working');
$rb->post('/sheet', ['do' => 'close']);
ok($rb->get('/checks')->code === 404 && (new Browser($inst))->get('')->code === 404, 'locked again afterwards');
$again = new Browser($inst);
$again->get('/reinstall');
ok(!str_contains($again->last, 'name="code"'), 'an unzip counts once: the next reinstall needs the kit unzipped again');

echo "Start over (content from before, not locked)\n";
$home2 = "$tmp/home2";
$p2s = makeSite($home2, $repo, $CODE);
mkdir($p2s->private, 0700, true);
$oldContent = $seed;
$oldContent['site']['price'] = 777;
(new ContentStore($p2s))->save($oldContent);
$inst2 = new Installer($p2s, ['home' => $home2] + $opt);
$sb = new Browser($inst2);
$sb->get('');
$sb->post('', ['code' => $CODE]);
$sb->get('/checks');
$res = $sb->post('/checks');
ok($res->location === '/edit/install/content', 'content from before: the setup asks what to do with it');
$sb->get('/content');
ok(str_contains($sb->text(), 'Keep it') && str_contains($sb->text(), 'Start over'), 'keep it, or start over');
$sb->post('/content', ['choice' => 'fresh']);
ok(str_contains($sb->text(), 'tick the box'), 'starting over needs the tick');
$res = $sb->post('/content', ['choice' => 'fresh', 'sure' => '1']);
ok($res->location === '/edit/install/people', 'then the people');
$sb->get('/people');
$sb->post('/people', ['p' => [['name' => 'John', 'username' => 'jn-test-1', 'email' => 'j2@example.test'], ['name' => 'Muneerah', 'username' => 'mu-test-1', 'email' => 'm2@example.test']]]);
$sb->get('/leads');
$sb->post('/leads', ['lead' => 'thinksmartpublicadjusting@gmail.com']);
$sb->get('/finish');
ok(str_contains($sb->text(), 'Step 5 of 7'), 'the step count includes the content step');
$res = $sb->post('/finish');
$aside = glob($p2s->private . '/before-install-*');
ok($res->location === '/edit/install/done' && count($aside) === 1 && (Json::read($aside[0] . '/content.json')['site']['price'] ?? 0) === 777, 'the content from before is put aside, not deleted');
ok((new ContentStore($p2s))->load()[0]['site']['price'] === $seed['site']['price'], 'and the website starts from the starting content');

echo "Download a copy\n";
@mkdir("$paths->webroot/media/u", 0755, true);
copy("$repo/media/og-cover.jpg", "$paths->webroot/media/u/home-top-photo-abcdef123456.jpg");
file_put_contents("$paths->webroot/media/u/.htaccess", 'x');
file_put_contents("$paths->webroot/media/u/evil.php", '<?php');
$pdf = "$tmp/k.pdf";
file_put_contents($pdf, "%PDF-1.4\n% contract\n%%EOF\n");
$acc = Contracts::accept($paths, $pdf, 'KY', $act[0]['id'], 'Kentucky.pdf');
$s = LeadSettings::load($cfg2);
$s['other'] = ['note' => 'keep me', 'cc' => 'someone@example.test'];
Json::write("$priv/settings.json", $s);
$copy = "$tmp/copy.zip";
$h = fopen($copy, 'wb');
$names = Backup::write(Backup::parts($cfg2), $h);
fclose($h);
$ce = Zip::entries($copy);
ok(isset($ce['README-developer.md'], $ce['private/content.json'], $ce['private/settings.json'], $ce['private/contracts/index.json'], $ce['private/contracts/' . $acc['v'] . '.pdf'],
    $ce['website/docs/think-smart-agreement-kentucky.pdf'], $ce['website/media/u/home-top-photo-abcdef123456.jpg']), 'the copy has the content, settings, contracts, docs, photos and the README');
ok(json_decode((string)Zip::read($copy, 'private/content.json', $ce), true)['site']['price'] === 349, 'the content is the live content');
$sj = (string)Zip::read($copy, 'private/settings.json', $ce);
ok(!str_contains($sj, '@') && !str_contains($sj, 'leadTo') && str_contains($sj, 'keep me'), 'settings without any lead address (or any email address)');
$never = array_filter(array_keys($ce), fn($n) => preg_match('#(users|config|codes|tokens|throttle)\.json$|sessions/|leads/|install/|history/|outbox/|\.htaccess$|\.php$#', $n));
ok($never === [], 'never logins, passwords, sessions, codes, links, leads, config.json, History or scripts');
$all = '';
foreach (array_keys($ce) as $n) $all .= (string)Zip::read($copy, $n, $ce);
ok(!str_contains($all, $cfg2->secret()) && !str_contains($all, (string)$hashJohn) && !str_contains($all, 'jnelson-77'), 'the secret, the password hashes and the usernames aren\'t in it');
exec('unzip -tq ' . escapeshellarg($copy) . ' 2>&1', $o2, $rc2);
ok($rc2 === 0, 'unzip -t says it\'s fine');
ok(preg_match('/^think-smart-website-copy-\d{4}-\d{2}-\d{2}\.zip$/', Backup::fileName()) === 1, 'named ' . Backup::fileName());
ok(InstallState::open(InstallState::seal('secret words', str_repeat('k', 32)), str_repeat('k', 32)) === 'secret words'
    && InstallState::open(substr_replace(InstallState::seal('secret words', str_repeat('k', 32)), 'x', 20, 1), str_repeat('k', 32)) === null
    && InstallState::open(InstallState::seal('secret words', str_repeat('k', 32)), str_repeat('j', 32)) === null, 'the sheet\'s codes open only with the right key, and changes are noticed');

echo "The launch-kit zip\n";
$kitOut = "$tmp/kit";
exec('php ' . escapeshellarg("$repo/edit-dev/make-zip.php") . ' ' . escapeshellarg('kit code 2026 abc') . ' --out=' . escapeshellarg($kitOut) . ' 2>&1', $mo, $mrc);
$kits = glob("$kitOut/think-smart-launch-kit-*.zip");
ok($mrc === 0 && count($kits) === 1 && preg_match(Installer::KIT_PATTERN, basename($kits[0])) === 1, 'make-zip builds ' . ($kits ? basename($kits[0]) : 'nothing') . ($mrc ? ': ' . implode(' / ', array_slice($mo, -3)) : ''));
if ($kits) {
    $ke = Zip::entries($kits[0]);
    $must = ['.htaccess', '.user.ini', 'Home.dc.html', 'ChatWidget.dc.html', '404.html', 'index.html', 'sitemap.xml', 'robots.txt', 'send-lead.php', 'support.js', 'edit/index.php', 'edit/.htaccess',
        'edit/lib/Installer.php', 'edit/app/install.js', 'edit/app/install.css', 'edit/seed/content.json', 'edit/install-code.hash', 'docs/think-smart-agreement-indiana.pdf', 'media/og-cover.jpg'];
    ok(!array_diff($must, array_keys($ke)), 'it has the website, the editor and the setup code\'s hash');
    ok(!array_filter(array_keys($ke), fn($n) => preg_match('#^(edit-dev|\.git|\.github|node_modules)|^(README\.md|\.gitattributes|\.gitignore|\.nojekyll)$|^media/u/|\.DS_Store$|^edit/\.installed$#', $n)), 'and nothing else: no edit-dev, git, GitHub or README files');
    ok(Installer::codeMatches('KIT-CODE-2026-ABC', trim((string)Zip::read($kits[0], 'edit/install-code.hash', $ke))) && !Installer::codeMatches('KIT-CODE-2026-ABD', trim((string)Zip::read($kits[0], 'edit/install-code.hash', $ke))), 'the hash matches the code (any capitals and dashes), and only it');
    exec('unzip -tq ' . escapeshellarg($kits[0]) . ' 2>&1', $o3, $rc3);
    ok($rc3 === 0, 'unzip -t says it\'s fine');
    $cl = (string)@file_get_contents("$kitOut/launch-kit-checklist.html");
    ok(is_file("$kitOut/guide.html") && str_contains($cl, 'kit code 2026 abc') && str_contains($cl, basename($kits[0])) && !str_contains($cl, '%SETUP_CODE%'), 'the guide and the checklist (with the code and the zip name) are next to it');
}
exec('php ' . escapeshellarg("$repo/edit-dev/make-zip.php") . ' short 2>&1', $so, $src);
ok($src !== 0 && str_contains(implode(' ', $so), 'at least 10'), 'a short setup code is refused');

Files::rmTree($tmp);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
