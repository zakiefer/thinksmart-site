<?php
// The installer at /edit/install, clicked through by John and Muneerah on a screen-share.
// Nothing happens without the setup code from the launch kit (edit/install-code.hash holds
// only its hash; wrong codes slow down). Then, in plain words:
//
//   1. checks: PHP, https, the website's address, the main website of the hosting, upload
//      size, folders, room on the hosting (zlib and imap are only noted)
//   2. the private folder: made (0700), a random probe file put in it, and proved closed to
//      the web (domain, www, the server's IP, IP/~user)
//   3. content from before, if any: keep it, or start over (put aside, never deleted)
//   4. who can edit: John (gets change alerts) and Muneerah, with private usernames
//   5. where leads are emailed (LeadSettings: a test lead with a confirm button)
//   6. set it up: config.json, content, the pages built and put live, the launch-day History
//      entry, set-your-password codes (on screen and emailed: each person saves the password
//      on their own iPhone at /edit/?code), 2 rescue codes each, the old lead logs moved into
//      the editor and deleted, the developer README, the pristine copy, the leftover zip, locks
//   7. the emergency sheet (printed), with one rescue code each
//
// Afterwards /edit/install answers 404 (two locks: <private>/install.lock and
// edit/.installed), unless "Reinstall and keep my content" is started at
// /edit/install/reinstall with the setup code again, within a day of unzipping the launch kit
// into the website folder again (only the hosting's own login can do that; John is emailed when a
// reinstall starts and when it's done): that keeps content.json, users.json,
// settings.json, History and leads, makes a new secret (everyone signs in again), and
// rebuilds the pages. It never copies anything over files it doesn't own.
//
// The codes it shows are kept for the emergency sheet only, in <private>/install/, encrypted
// with a key that lives in the installer's cookie (never readable from the disk alone).
declare(strict_types=1);

namespace ThinkSmart;

/** One request to the installer, from PHP's globals (or made up by the tests). */
final class InstallRequest
{
    public string $method = 'GET';
    public string $route = '';
    public array $query = [];
    public array $post = [];
    public array $cookies = [];
    public bool $https = false;
    public string $host = '';
    public string $ip = '127.0.0.1';
    public ?string $origin = null;
    public ?string $fetchSite = null;
    public string $docRoot = '';
    public string $serverAddr = '';

    public static function fromGlobals(string $rest): self
    {
        $r = new self();
        $m = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $r->method = $m === 'HEAD' ? 'GET' : $m;
        $r->route = self::route($rest);
        $r->query = $_GET;
        $r->post = $_POST;
        $r->cookies = $_COOKIE;
        $r->https = Http::isHttps();
        $r->host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $r->ip = Http::ip();
        $r->origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : null;
        $r->fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? (string)$_SERVER['HTTP_SEC_FETCH_SITE'] : null;
        $r->docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $r->serverAddr = (string)($_SERVER['SERVER_ADDR'] ?? '');
        return $r;
    }

    /** "" for /edit/install, "/checks" for /edit/install/checks/ */
    public static function route(string $rest): string
    {
        $rest = trim($rest, '/');
        return $rest === '' ? '' : '/' . $rest;
    }

    /** The host as typed, if it's a plain name or address (with an optional port). */
    public function safeHost(): string
    {
        return preg_match('/^([a-z0-9-]+(\.[a-z0-9-]+)*|\[[0-9a-f:.]+\])(:\d{1,5})?$/', $this->host) ? $this->host : '';
    }

    /** The host without its port. */
    public function hostName(): string
    {
        return (string)preg_replace('/:\d{1,5}$/', '', $this->safeHost());
    }

    public function port(): ?int
    {
        return preg_match('/:(\d{1,5})$/', $this->safeHost(), $m) ? (int)$m[1] : null;
    }

    public function isLocal(): bool
    {
        $h = $this->hostName();
        return in_array($h, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($h, '.localhost');
    }

    public function origin(): string
    {
        return ($this->https ? 'https' : 'http') . '://' . $this->host;
    }

    /** A POST must come from the installer's own pages. */
    public function sameOrigin(): bool
    {
        if ($this->origin !== null && ($this->origin === 'null' || strtolower($this->origin) !== strtolower($this->origin()))) return false;
        if ($this->fetchSite !== null && !in_array($this->fetchSite, ['same-origin', 'none'], true)) return false;
        return true;
    }
}

/** What the installer answers: a page or a redirect, plus cookies (on /edit/install only). */
final class InstallResponse
{
    public int $code = 200;
    public string $body = '';
    public ?string $location = null;
    /** @var list<array{0:string,1:string,2:int}> name, value, seconds (negative removes it) */
    public array $cookies = [];

    public static function page(string $html, int $code = 200): self
    {
        $r = new self();
        $r->body = $html;
        $r->code = $code;
        return $r;
    }

    public static function redirect(string $to): self
    {
        $r = new self();
        $r->code = 303;
        $r->location = $to;
        return $r;
    }

    public function cookie(string $name, string $value, int $seconds): self
    {
        $this->cookies[] = [$name, $value, $seconds];
        return $this;
    }

    /** The cookie's value after this response (for the tests' little browser). */
    public function cookieValue(string $name): ?string
    {
        $v = null;
        foreach ($this->cookies as [$n, $val, $s]) if ($n === $name) $v = $s < 0 ? '' : $val;
        return $v;
    }

    public function emit(bool $https): void
    {
        Http::headers('page');
        http_response_code($this->code);
        if (!headers_sent()) {
            foreach ($this->cookies as [$n, $v, $s]) {
                setcookie($n, $v, ['expires' => $s < 0 ? 1 : time() + $s, 'path' => '/edit/install', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
            }
            if ($this->location !== null) header('Location: ' . $this->location, true, $this->code);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $this->body;
    }
}

/** The checks, each in plain words with one thing to do, and the private folder's proof. */
final class InstallChecks
{
    public const UPLOAD_NEEDED = 16 * 1024 * 1024;
    public const PHP_MIN = 80100;
    public const PHP_GOOD = 80300;
    public const ROOM_TEST = 24 * 1024 * 1024;

    private const PRIMARY_FIX = 'Call GoDaddy and say: "Please make thinksmartpublicadjusting.com the primary domain of my cPanel account, with public_html as its folder." Then upload the website into public_html and start this setup again.';

    public static function row(string $id, string $level, string $title, string $text, string $action = ''): array
    {
        return ['id' => $id, 'level' => $level, 'title' => $title, 'text' => $text, 'action' => $action];
    }

    /** @return list<array> every check before the private folder, in the order shown */
    public static function all(Paths $p, InstallRequest $r, array $opt): array
    {
        $ini = $opt['ini'] ?? ['upload_max_filesize' => (string)ini_get('upload_max_filesize'), 'post_max_size' => (string)ini_get('post_max_size')];
        return [
            self::php(),
            self::https($r, !empty($opt['dev'])),
            self::address($r, !empty($opt['dev'])),
            self::primary($p, !empty($opt['dev']), array_key_exists('home', $opt) ? $opt['home'] : self::home()),
            self::uploads((string)$ini['upload_max_filesize'], (string)$ini['post_max_size']),
            self::folders($p),
            self::disk($p),
        ];
    }

    public static function php(int $id = PHP_VERSION_ID, string $v = PHP_VERSION): array
    {
        $how = 'In cPanel, open MultiPHP Manager, tick this website, pick the newest PHP version in the list and press Apply. Then press Check again.';
        if ($id < self::PHP_MIN) return self::row('php', 'stop', 'PHP version', "This hosting runs PHP $v, which is too old for the editor (it needs 8.1 or newer).", $how);
        if ($id < self::PHP_GOOD) return self::row('php', 'warn', 'PHP version', "PHP $v works, but a newer one is better.", $how);
        return self::row('php', 'ok', 'PHP version', "PHP $v.");
    }

    public static function https(InstallRequest $r, bool $dev): array
    {
        if ($r->https) return self::row('https', 'ok', 'Secure address', 'This page opened with https:// at the start.');
        if ($dev) return self::row('https', 'ok', 'Secure address', 'Testing on this computer, where https isn\'t needed.');
        return self::row('https', 'stop', 'Secure address', 'This page opened without https:// at the start.',
            'Open https://' . ($r->hostName() ?: 'www.thinksmartpublicadjusting.com') . '/edit/install instead. If that shows a warning, the security certificate isn\'t ready: in cPanel, open SSL/TLS Status, press Run AutoSSL and wait 10 minutes.');
    }

    public static function address(InstallRequest $r, bool $dev): array
    {
        $h = $r->hostName();
        if ($dev) return self::row('address', 'ok', 'Website address', "Testing on this computer ($h).");
        $ip = filter_var(trim($h, '[]'), FILTER_VALIDATE_IP) !== false;
        if ($h === '' || $ip || !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $h)) {
            return self::row('address', 'stop', 'Website address', 'This page wasn\'t opened at the website\'s own address' . ($h !== '' ? " (it's $h)" : '') . '.',
                'Open https://www.thinksmartpublicadjusting.com/edit/install instead.');
        }
        if (!preg_match('/(^|\.)thinksmartpublicadjusting\.com$/', $h)) {
            return self::row('address', 'warn', 'Website address', "This is $h, not thinksmartpublicadjusting.com. Links in the editor's emails will use https://$h.",
                'If that\'s not right, open https://www.thinksmartpublicadjusting.com/edit/install instead.');
        }
        return self::row('address', 'ok', 'Website address', "https://$h (links in the editor's emails use it).");
    }

    /**
     * The website must be the hosting's main website: its folder is ~/public_html, so the
     * private folder beside it is outside every website folder. (As a second website of the
     * account, "one level up" would sit inside the main website's folder.)
     */
    public static function primary(Paths $p, bool $dev, ?string $home): array
    {
        if ($dev) return self::row('primary', 'ok', 'Main website of the hosting', 'Testing on this computer.');
        $web = self::absPath($p->webroot);
        $parent = dirname($web);
        $bad = basename($web) !== 'public_html' || in_array('public_html', explode('/', $parent), true);
        if (!$bad && $home !== null && ($rh = realpath($home)) !== false && $rh !== $parent) $bad = true;
        if (!$bad) return self::row('primary', 'ok', 'Main website of the hosting', "The website is in $web, the hosting's main website folder.");
        return self::row('primary', 'stop', 'Main website of the hosting', "The website is in $web. That isn't the main website folder of this cPanel account (public_html in its home folder), so the private files could be reachable from the web.", self::PRIMARY_FIX);
    }

    /** "16M" → bytes ("0" means no limit). */
    public static function bytes(string $v): int
    {
        if (!preg_match('/^\s*(\d+)\s*([KMG]?)/i', $v, $m)) return 0;
        $n = (int)$m[1];
        return $n * ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])];
    }

    public static function uploads(string $up, string $post): array
    {
        $u = self::bytes($up);
        $pm = self::bytes($post);
        if (($u === 0 || $u >= self::UPLOAD_NEEDED) && ($pm === 0 || $pm >= self::UPLOAD_NEEDED)) {
            $max = min($u ?: PHP_INT_MAX, $pm ?: PHP_INT_MAX);
            return self::row('uploads', 'ok', 'Upload size', 'Files up to ' . ($max === PHP_INT_MAX ? 'any size' : self::size($max)) . ' can be added (contracts can be up to 15 MB).');
        }
        return self::row('uploads', 'stop', 'Upload size', "Contracts can be up to 15 MB, so the hosting must take 16 MB uploads. Now it takes upload_max_filesize $up and post_max_size $post.",
            'In cPanel, open MultiPHP INI Editor, pick this website, set upload_max_filesize to 16M and post_max_size to 20M, and press Apply. It can take 5 minutes to work; then press Check again.');
    }

    public static function folders(Paths $p): array
    {
        $need = [
            'the website folder' => $p->webroot, 'docs' => $p->webroot . '/docs', 'media' => $p->webroot . '/media', 'edit' => $p->webroot . '/edit',
            is_dir($p->private) ? 'the private folder' : 'the home folder' => is_dir($p->private) ? $p->private : dirname($p->private),
        ];
        $missing = [];
        $locked = [];
        foreach ($need as $what => $dir) {
            if (!is_dir($dir)) $missing[] = $what;
            elseif (!is_writable($dir)) $locked[] = "$what ($dir)";
        }
        if ($missing) return self::row('folders', 'stop', 'Folders', 'These folders are missing: ' . implode(', ', $missing) . '.',
            'Upload the launch kit zip into public_html again and extract it there (cPanel → File Manager → Extract).');
        if ($locked) return self::row('folders', 'stop', 'Folders', 'The editor can\'t save into: ' . implode(', ', $locked) . '.',
            'In cPanel, open File Manager, right-click each of these folders, choose Change Permissions and tick Write for User (755). Then press Check again.');
        return self::row('folders', 'ok', 'Folders', 'The editor can save pages, photos, contracts and its private files.');
    }

    public static function disk(Paths $p): array
    {
        $dir = is_dir($p->private) ? $p->private : dirname($p->private);
        $free = function_exists('disk_free_space') ? @disk_free_space($dir) : false;
        if (!is_float($free) && !is_int($free)) return self::row('disk', 'info', 'Room on the hosting', 'This hosting doesn\'t say. It\'s tested when the private folder is made.');
        if ($free < 200 * 1048576) return self::row('disk', 'warn', 'Room on the hosting', 'Only ' . self::size((int)$free) . ' is free.',
            'In cPanel, open Disk Usage to see what uses the room, or ask GoDaddy for more.');
        return self::row('disk', 'ok', 'Room on the hosting', self::size((int)$free) . ' free.');
    }

    /** Optional parts of PHP: only noted, the editor works without them. */
    public static function extras(): array
    {
        return [
            ['zlib', function_exists('gzencode'), 'smaller History files and copies'],
            ['imap', function_exists('imap_open'), 'lets the editor notice lead emails that bounced'],
            ['curl', function_exists('curl_multi_init'), 'faster web checks in this setup'],
            ['openssl', extension_loaded('openssl'), 'https checks and the certificate date'],
        ];
    }

    public static function size(int $b): string
    {
        if ($b >= 1073741824) return round($b / 1073741824, 1) . ' GB';
        if ($b >= 1048576) return round($b / 1048576) . ' MB';
        return max(1, (int)round($b / 1024)) . ' KB';
    }

    /** A path with its links resolved, even if its last parts don't exist yet. */
    public static function absPath(string $path): string
    {
        $path = rtrim($path, '/');
        if ($path === '') return '/';
        $rp = realpath($path);
        if ($rp !== false) return $rp;
        $parent = dirname($path);
        return $parent === $path ? $path : rtrim(self::absPath($parent), '/') . '/' . basename($path);
    }

    public static function home(): ?string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && !empty($pw['dir'])) return (string)$pw['dir'];
        }
        $h = getenv('HOME');
        return is_string($h) && $h !== '' && $h !== '/' ? $h : null;
    }

    /** The hosting account's user name (for the IP/~user address). */
    public static function user(Paths $p): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && !empty($pw['name'])) return (string)$pw['name'];
        }
        $u = (string)@get_current_user();
        if ($u !== '' && $u !== 'root') return $u;
        return basename(dirname(self::absPath($p->webroot)));
    }

    /** Can a file of $bytes be written here? (the hosting's quota, not just the disk) */
    public static function roomFor(string $dir, int $bytes): bool
    {
        $f = $dir . '/.room-check-' . bin2hex(random_bytes(4)) . '.tmp';
        $h = @fopen($f, 'wb');
        if ($h === false) return false;
        $chunk = str_repeat("\0", 1048576);
        $ok = true;
        for ($i = 0; $i < intdiv($bytes, 1048576) && $ok; $i++) $ok = @fwrite($h, $chunk) === 1048576;
        $ok = $ok && @fflush($h);
        $ok = @fclose($h) && $ok;
        clearstatcache(true, $f);
        $ok = $ok && @filesize($f) >= $bytes;
        @unlink($f);
        return $ok;
    }

    /**
     * Make the private folder (0700) and prove it can't be opened from the web: not inside the
     * website's folder, room for a contract, and a random probe file that must not come back
     * through the website's address, www, the server's IP or IP/~user (3 seconds each; no
     * answer or a refused connection counts as closed). The website's names are sent straight
     * to this server's address, so the test works even before the DNS switch. A folder with
     * nothing in it yet is opened up for traffic (0711) during the few seconds of the test, so a
     * wrong layout really shows; a folder that already holds data stays 0700, and then a
     * "forbidden" answer where a made-up name gets "not found" counts as reachable too. If the
     * hosting can't fetch even its own editor stylesheet, nothing was proved: an orange note.
     * @return array a check row, plus 'tried' (addresses) and 'found' (where it answered)
     */
    public static function makePrivate(Paths $p, InstallRequest $r, array $opt = []): array
    {
        $dir = $p->private;
        $target = self::absPath($dir);
        $title = 'Private folder';
        foreach (array_filter([$r->docRoot !== '' ? self::absPath($r->docRoot) : '', self::absPath($p->webroot)]) as $root) {
            if ($target === $root || str_starts_with($target . '/', rtrim($root, '/') . '/')) {
                return self::row('private', 'stop', $title, "The private folder ($target) would be inside the website's folder ($root), where anyone could open it.", self::PRIMARY_FIX) + ['tried' => [], 'found' => []];
            }
        }
        $fresh = !is_dir($dir);
        if ($fresh && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return self::row('private', 'stop', $title, "The private folder couldn't be made at $dir.",
                'In cPanel, open File Manager and check that the home folder can be written (Change Permissions: Write for User). Then press Check again.') + ['tried' => [], 'found' => []];
        }
        @chmod($dir, 0700);
        clearstatcache(true, $dir);
        if ((fileperms($dir) & 0777) !== 0700) {
            return self::row('private', 'stop', $title, "The private folder ($dir) couldn't be closed to other accounts.",
                'In cPanel, open File Manager, right-click thinksmart-private, choose Change Permissions and tick only the three User boxes (700). Then press Check again.') + ['tried' => [], 'found' => []];
        }
        if (!self::roomFor($dir, self::ROOM_TEST)) {
            return self::row('private', 'stop', $title, 'The hosting is nearly full: there isn\'t room for a 24 MB test file.',
                'In cPanel, open Disk Usage to see what uses the room, or ask GoDaddy for more. Then press Check again.') + ['tried' => [], 'found' => []];
        }
        $empty = !array_diff(scandir($dir) ?: [], ['.', '..', 'install', 'locks']);
        $probeDir = 'probe-' . bin2hex(random_bytes(8));
        $token = 'ts-probe-' . bin2hex(random_bytes(16));
        @mkdir("$dir/$probeDir", 0755);
        @chmod("$dir/$probeDir", 0755);
        $wrote = @file_put_contents("$dir/$probeDir/p.txt", $token . "\n") !== false;
        @chmod("$dir/$probeDir/p.txt", 0644);
        if ($empty) @chmod($dir, 0711);
        try {
            if (!$wrote) throw new \RuntimeException('probe not written');
            $bases = $opt['probeBases'] ?? self::probeBases($r, !empty($opt['dev']));
            $urls = self::probeUrls($p, $r, "$probeDir/p.txt", $bases, $opt['user'] ?? null);
            $control = [];
            foreach ($urls as $u) $control[$u] = self::controlUrl($u, basename($target));
            // a file that is surely there: shows whether this hosting can reach itself at all
            $self = array_map(fn($b) => rtrim((string)$b, '/') . '/edit/app/install.css', $bases);
            $fetch = $opt['fetch'] ?? null;
            $all = array_values(array_unique(array_merge($urls, array_values($control), $self)));
            $got = $fetch ? $fetch($all) : self::fetchAll($all, 3, !isset($opt['curl']) || $opt['curl'], $opt['pin'] ?? self::pins($r));
        } catch (\Throwable $e) {
            $urls = [];
            $got = [];
            $control = [];
            $self = [];
            Log::error('install probe: ' . $e->getMessage());
        } finally {
            @chmod($dir, 0700);
            if (is_file("$dir/$probeDir/p.txt")) @unlink("$dir/$probeDir/p.txt");
            if (is_dir("$dir/$probeDir")) @rmdir("$dir/$probeDir");
        }
        if (!$wrote) {
            return self::row('private', 'stop', $title, "A test file couldn't be written into the private folder ($dir).",
                'In cPanel, open File Manager, right-click thinksmart-private, choose Change Permissions and tick the three User boxes (700). Then press Check again.') + ['tried' => [], 'found' => []];
        }
        $found = [];
        foreach ($urls as $u) {
            $a = $got[$u] ?? null;
            if (!is_array($a)) continue;
            $c = $got[$control[$u]] ?? null;
            if (str_contains((string)($a['body'] ?? ''), $token)) $found[] = $u;
            elseif (in_array((int)($a['code'] ?? 0), [401, 403], true) && is_array($c) && (int)($c['code'] ?? 0) === 404) $found[] = $u;
        }
        if ($found) {
            return self::row('private', 'stop', $title, 'The private folder can be opened from the web, at ' . $found[0] . '. The setup stops here, so nothing private is put there.', self::PRIMARY_FIX)
                + ['tried' => $urls, 'found' => $found];
        }
        $reached = false;
        foreach ($self as $u) if ((int)($got[$u]['code'] ?? 0) === 200) $reached = true;
        if (!$reached) {
            $host = $r->hostName() ?: 'www.thinksmartpublicadjusting.com';
            return self::row('private', 'warn', $title, ($fresh ? 'Made ' : 'Found ') . "$target, outside the website folder. This hosting couldn't open its own website from the inside, so the test from the web couldn't run.",
                "Carry on. To double-check by hand, open https://$host/" . basename($target) . '/ in a browser: it must say Not Found.') + ['tried' => $urls, 'found' => []];
        }
        return self::row('private', 'ok', $title, ($fresh ? 'Made ' : 'Found ') . "$target. Only this hosting account can open it: " . count($urls) . ' tries to open it from the web all failed, as they should.')
            + ['tried' => $urls, 'found' => []];
    }

    /**
     * The website's own names pinned to this server's address, so the test reaches this
     * hosting even while the domain still points somewhere else (before the DNS switch).
     * @return array<string,string> name => IPv4 address
     */
    public static function pins(InstallRequest $r): array
    {
        $ip = $r->serverAddr;
        $host = $r->hostName();
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) return [];
        $other = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        return [$host => $ip, $other => $ip];
    }

    /** The same address with the private folder's name changed to one that doesn't exist. */
    private static function controlUrl(string $url, string $name): string
    {
        $pos = strrpos($url, '/' . $name . '/');
        return $pos === false ? $url . '-none' : substr($url, 0, $pos) . '/' . $name . '-none' . substr($url, $pos + strlen($name) + 1);
    }

    /**
     * Where the probe could show up if the layout were wrong: the private folder seen from each
     * of its three nearest parent folders and from ~user, through the website's address, www
     * (or the bare name), and the server's IP, over https and http.
     */
    public static function probeUrls(Paths $p, InstallRequest $r, string $rel, ?array $bases = null, ?string $user = null): array
    {
        $priv = self::absPath($p->private);
        $parts = explode('/', trim($priv, '/'));
        $paths = [];
        for ($k = 1; $k <= min(3, count($parts)); $k++) $paths[] = '/' . implode('/', array_map('rawurlencode', array_slice($parts, -$k))) . '/' . $rel;
        $user = $user ?? self::user($p);
        if ($user !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $user)) $paths[] = '/~' . $user . '/' . rawurlencode(basename($priv)) . '/' . $rel;
        $bases = $bases ?? self::probeBases($r);
        $urls = [];
        foreach ($bases as $b) foreach ($paths as $pp) $urls[] = rtrim((string)$b, '/') . $pp;
        return array_values(array_unique($urls));
    }

    /**
     * https:// and http:// through the website's address, www (or the bare name) and the server's IP.
     * On the hosting the names are only tried pinned to this server's own address, on the usual
     * ports, so a made-up Host header can't send the test anywhere else; a local test keeps its port.
     */
    public static function probeBases(InstallRequest $r, bool $dev = false): array
    {
        $bases = [];
        $host = $r->hostName();
        $port = $dev ? $r->port() : null;
        $names = [];
        if ($host !== '' && ($dev || self::pins($r))) {
            $names[] = $host;
            if (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) $names[] = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        }
        $ip = $r->serverAddr;
        if (filter_var($ip, FILTER_VALIDATE_IP)) $names[] = str_contains($ip, ':') ? "[$ip]" : $ip;
        foreach (array_unique($names) as $n) {
            foreach (['https', 'http'] as $scheme) $bases[] = "$scheme://$n" . ($port !== null ? ":$port" : '');
        }
        return $bases;
    }

    /**
     * GET each address once (3 seconds each, certificates not checked, redirects not followed:
     * every address worth trying is already in the list).
     * $pin sends a name to a given IPv4 address instead of looking it up.
     * @return array<string, array{code:int, body:string}|null> null: no answer
     */
    public static function fetchAll(array $urls, int $timeout = 3, bool $useCurl = true, array $pin = []): array
    {
        $out = array_fill_keys($urls, null);
        if (!$urls) return $out;
        if ($useCurl && function_exists('curl_multi_init')) {
            $mh = curl_multi_init();
            $hs = [];
            foreach ($urls as $u) {
                $h = curl_init($u);
                if ($h === false) continue;
                curl_setopt_array($h, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_USERAGENT => 'ThinkSmart website setup check', CURLOPT_NOSIGNAL => true,
                ]);
                if ($pin) {
                    $ports = array_unique([80, 443, (int)(parse_url($u, PHP_URL_PORT) ?: 80)]);
                    $resolve = [];
                    foreach ($pin as $name => $ip) foreach ($ports as $port) $resolve[] = "$name:$port:$ip";
                    curl_setopt($h, CURLOPT_RESOLVE, $resolve);
                }
                curl_multi_add_handle($mh, $h);
                $hs[$u] = $h;
            }
            $until = microtime(true) + $timeout + 2;
            do {
                $st = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 0.25);
            } while ($running > 0 && $st === CURLM_OK && microtime(true) < $until);
            foreach ($hs as $u => $h) {
                $code = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
                $body = curl_multi_getcontent($h);
                $out[$u] = $code > 0 ? ['code' => $code, 'body' => substr(is_string($body) ? $body : '', 0, 65536)] : null;
                curl_multi_remove_handle($mh, $h);
            }
            return $out;
        }
        $dead = [];
        foreach ($urls as $u) {
            $base = (string)preg_replace('~^([a-z]+://[^/]+).*$~', '$1', $u);
            if (isset($dead[$base])) continue;
            $a = self::fetchOne($u, $timeout, $connected, $pin);
            if (!$connected) $dead[$base] = true;
            $out[$u] = $a;
        }
        return $out;
    }

    /** One plain HTTP/1.0 GET over a socket (for hosts without curl). */
    private static function fetchOne(string $url, int $timeout, ?bool &$connected = null, array $pin = []): ?array
    {
        $connected = false;
        $q = parse_url($url);
        if (!is_array($q) || empty($q['host'])) return null;
        $https = ($q['scheme'] ?? 'http') === 'https';
        if ($https && !extension_loaded('openssl')) return null;
        $host = (string)$q['host'];
        $port = (int)($q['port'] ?? ($https ? 443 : 80));
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
            'SNI_enabled' => true, 'peer_name' => trim($host, '[]')]]);
        $s = @stream_socket_client(($https ? 'ssl://' : 'tcp://') . ($pin[$host] ?? $host) . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) return null;
        $connected = true;
        stream_set_timeout($s, $timeout);
        $path = ($q['path'] ?? '/') . (isset($q['query']) ? '?' . $q['query'] : '');
        @fwrite($s, "GET $path HTTP/1.0\r\nHost: " . $host . (isset($q['port']) ? ':' . $port : '') . "\r\nUser-Agent: ThinkSmart website setup check\r\nConnection: close\r\n\r\n");
        $raw = '';
        $until = microtime(true) + $timeout;
        while (!feof($s) && strlen($raw) < 70000 && microtime(true) < $until) {
            $chunk = @fread($s, 8192);
            if ($chunk === false) break;
            if ($chunk === '') { $meta = stream_get_meta_data($s); if (!empty($meta['timed_out'])) break; continue; }
            $raw .= $chunk;
        }
        fclose($s);
        if (!preg_match('~^HTTP/\d(?:\.\d)?\s+(\d{3})~', $raw, $m)) return null;
        $at = strpos($raw, "\r\n\r\n");
        return ['code' => (int)$m[1], 'body' => $at === false ? '' : substr($raw, $at + 4, 65536)];
    }
}

/**
 * One installer session's progress (<private>/install/state-<sid>.json) and the codes it
 * shows (codes-<sid>.bin: encrypted with the key in the installer's cookie).
 */
final class InstallState
{
    public array $d;
    private string $file;
    private string $codesFile;

    public function __construct(Paths $p, string $sid)
    {
        $dir = self::dir($p);
        $this->file = "$dir/state-$sid.json";
        $this->codesFile = "$dir/codes-$sid.bin";
        $d = Json::read($this->file, null);
        $this->d = is_array($d) ? $d : ['sid' => $sid, 'made' => Clock::now(), 'done' => []];
    }

    public static function dir(Paths $p): string
    {
        return $p->private . '/install';
    }

    public function save(): void
    {
        Json::write($this->file, $this->d);
        @chmod($this->file, 0600);
    }

    public function done(string $step): bool
    {
        return !empty($this->d['done'][$step]);
    }

    public function mark(string $step): void
    {
        $this->d['done'][$step] = Clock::now();
        $this->save();
    }

    public function codes(string $key): array
    {
        $blob = is_file($this->codesFile) ? (string)@file_get_contents($this->codesFile) : '';
        $plain = $blob !== '' ? self::open($blob, $key) : null;
        $v = $plain !== null ? json_decode($plain, true) : null;
        return is_array($v) ? $v : [];
    }

    public function putCodes(string $key, array $codes): void
    {
        Files::writeAtomic($this->codesFile, self::seal((string)json_encode($codes), $key));
        @chmod($this->codesFile, 0600);
    }

    public function hasCodes(): bool
    {
        return is_file($this->codesFile);
    }

    public function forget(): void
    {
        foreach ([$this->codesFile, $this->file] as $f) if (is_file($f)) @unlink($f);
    }

    /** Remove other installer sessions (all of them, or those older than $maxAge seconds). */
    public static function tidy(Paths $p, ?string $keep, int $maxAge = 2 * 86400): void
    {
        $files = array_merge(glob(self::dir($p) . '/state-*.json') ?: [], glob(self::dir($p) . '/codes-*.bin') ?: []);
        foreach ($files as $f) {
            if ($keep !== null && preg_match('/-' . preg_quote($keep, '/') . '\.(json|bin)$/', $f)) continue;
            if ($maxAge === 0 || (int)@filemtime($f) < Clock::now() - $maxAge) @unlink($f);
        }
    }

    // Encrypt-then-MAC with HMAC-SHA256 as the stream cipher: core PHP only (no openssl/sodium).

    public static function seal(string $plain, string $key): string
    {
        $nonce = random_bytes(16);
        $ct = $plain ^ self::stream(hash_hmac('sha256', 'enc', $key, true), $nonce, strlen($plain));
        return $nonce . $ct . hash_hmac('sha256', $nonce . $ct, hash_hmac('sha256', 'mac', $key, true), true);
    }

    public static function open(string $blob, string $key): ?string
    {
        if (strlen($blob) < 48 || strlen($key) < 16) return null;
        $nonce = substr($blob, 0, 16);
        $ct = (string)substr($blob, 16, -32);
        $tag = substr($blob, -32);
        if (!hash_equals(hash_hmac('sha256', $nonce . $ct, hash_hmac('sha256', 'mac', $key, true), true), $tag)) return null;
        return $ct ^ self::stream(hash_hmac('sha256', 'enc', $key, true), $nonce, strlen($ct));
    }

    private static function stream(string $k, string $nonce, int $len): string
    {
        $out = '';
        for ($i = 0; strlen($out) < $len; $i++) $out .= hash_hmac('sha256', $nonce . pack('N', $i), $k, true);
        return (string)substr($out, 0, $len);
    }
}

final class Installer
{
    public const COOKIE = 'ts_i';
    public const FORM_COOKIE = 'ts_if';
    public const HOURS = 12;
    public const UNZIP_HOURS = 24;
    /** The launch kit's zip name (made by edit-dev/make-zip.php): the only zips the installer deletes. */
    public const KIT_PATTERN = '/^think-smart-launch-kit-\d{4}-\d{2}-\d{2}(-\d+)?( ?\(\d+\))?\.zip$/i';
    public const GODADDY_PHONE = '(480) 505-8877';
    public const SITE_EMAIL = 'thinksmartpublicadjusting@gmail.com';

    /** Private files a "Start over" puts aside (leads and the mail count stay where they are). */
    private const ASIDE = ['content.json', 'content.json.good', 'users.json', 'settings.json', 'history', 'drafts', 'sessions', 'codes.json',
        'tokens.json', 'throttle.json', 'status', 'prefs', 'words.json', 'filters.json', 'contracts', 'swap-journal.json', 'builds',
        'health.json', 'pristine', 'README-developer.md'];

    private Paths $paths;
    private array $opt;
    private bool $dev;
    private InstallRequest $req;
    private ?array $s = null;

    /**
     * Options: dev (local test: http allowed, mail to the outbox), mail ("mail" | "outbox"),
     * slow (wait a second after a wrong code), tmp (folder for the wrong-code count), and for
     * the tests: home, user, ini, fetch, probeBases, curl.
     */
    public function __construct(Paths $paths, array $opt = [])
    {
        $this->paths = $paths;
        $this->dev = !empty($opt['dev']);
        $this->opt = $opt + ['mail' => $this->dev ? 'outbox' : 'mail', 'slow' => true];
        $this->req = new InstallRequest();
    }

    /** /edit/install and /edit/install/… (edit/index.php → Editor). */
    public static function run(Paths $paths, string $rest): void
    {
        ini_set('display_errors', '0');
        $req = InstallRequest::fromGlobals($rest);
        $dev = getenv('TS_INSTALL_DEV') === '1' && $req->isLocal() && in_array($req->ip, ['127.0.0.1', '::1'], true);
        $inst = new self($paths, ['dev' => $dev]);
        try {
            $res = $inst->handle($req);
        } catch (\Throwable $e) {
            Log::error('installer: ' . get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
            $res = $inst->page('Something went wrong', '<h1>Something went wrong</h1><p>The setup hit a problem on the website\'s side. Nothing was lost. Go back and try that step again.</p>'
                . '<details class="inst-details"><summary>Details for the developer</summary><p class="pre">' . Http::esc($e->getMessage()) . '</p></details>', 500);
        }
        $res->emit($req->https);
    }

    public function handle(InstallRequest $req): InstallResponse
    {
        $this->req = $req;
        $route = $req->route;
        $locked = self::isLocked($this->paths);
        if ($route === '') return $this->start($locked, false);
        if ($route === '/reinstall') return $this->start($locked, true);
        if (!in_array($route, ['/checks', '/content', '/people', '/leads', '/finish', '/done', '/sheet'], true)) return $this->notFound();
        $this->s = $this->session();
        if ($this->s === null) return $locked ? $this->notFound() : InstallResponse::redirect('/edit/install');
        $st = is_dir(InstallState::dir($this->paths)) ? new InstallState($this->paths, $this->s['sid']) : null;
        if ($st && !empty($st->d['finished'])) {
            if ($route === '/done') return $this->done($st);
            if ($route === '/sheet') return $this->sheet($st);
            return $locked ? $this->notFound() : InstallResponse::redirect('/edit/install/done');
        }
        // once locked, only a session started at /edit/install/reinstall after the lock gets in
        if ($locked && (empty($this->s['re']) || (int)$this->s['iat'] < $this->lockTime())) return $this->notFound();
        if ($route === '/checks') return $this->checks($st);
        if (!$st || empty($st->d['private'])) return InstallResponse::redirect('/edit/install/checks');
        $next = $this->next($st);
        $order = ['/checks', '/content', '/people', '/leads', '/finish'];
        $want = array_search($route, $order, true);
        // a step can't be skipped ahead of the one still to do (/done and /sheet come after finishing)
        if ($want === false || $want > array_search($next, $order, true)) return InstallResponse::redirect('/edit/install' . $next);
        switch ($route) {
            case '/content': return $this->content($st);
            case '/people': return $this->people($st);
            case '/leads': return $this->leads($st);
            default: return $this->finish($st);
        }
    }

    // ---------------------------------------------------------------- the setup code

    public static function normalizeCode(string $code): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** What make-zip.php writes into edit/install-code.hash (bcrypt: made on one computer, checked on any PHP). */
    public static function codeHash(string $code): string
    {
        return password_hash(self::normalizeCode($code), PASSWORD_BCRYPT);
    }

    public static function codeMatches(string $typed, string $hash): bool
    {
        $c = self::normalizeCode($typed);
        return $c !== '' && strlen($c) <= 64 && password_verify($c, $hash);
    }

    public static function kitName(int $t, int $n = 1): string
    {
        return 'think-smart-launch-kit-' . Clock::local($t)->format('Y-m-d') . ($n > 1 ? "-$n" : '') . '.zip';
    }

    private function codeHashFile(): string
    {
        return $this->paths->webroot . '/edit/install-code.hash';
    }

    private function storedHash(): ?string
    {
        $f = $this->codeHashFile();
        $h = is_file($f) ? trim((string)@file_get_contents($f)) : '';
        return preg_match('/^\$2y\$\d\d\$[.\/A-Za-z0-9]{53}$|^\$argon2/', $h) ? $h : null;
    }

    /** Wrong codes slow down (like sign-in): per address after 5 tries, for everyone after 20. */
    private function throttleFile(): ?string
    {
        $dir = $this->opt['tmp'] ?? sys_get_temp_dir();
        if (!is_dir($dir) || !is_writable($dir)) $dir = is_dir($this->paths->private) ? $this->paths->private : '';
        return $dir === '' ? null : rtrim($dir, '/') . '/ts-install-' . substr(hash('sha256', $this->paths->webroot), 0, 16) . '.json';
    }

    private function throttle(callable $fn)
    {
        $f = $this->throttleFile();
        if ($f === null) return $fn([], fn() => null);
        $h = @fopen($f, 'c+');
        if ($h === false) return $fn([], fn() => null);
        try {
            flock($h, LOCK_EX);
            $d = json_decode((string)stream_get_contents($h), true);
            $d = is_array($d) ? $d : [];
            foreach ($d as $k => $e) if (!is_array($e) || (int)($e['last'] ?? 0) < Clock::now() - 86400) unset($d[$k]);
            $save = function (array $nd) use ($h) { ftruncate($h, 0); rewind($h); fwrite($h, (string)json_encode($nd)); fflush($h); };
            return $fn($d, $save);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private function ipKey(): string
    {
        return 'ip:' . substr(hash('sha256', 'ts-install|' . $this->req->ip), 0, 20);
    }

    private static function waitUntil(?array $e, int $offset = 0): int
    {
        if (!$e) return 0;
        $t = (int)$e['last'] + Throttle::wait((int)$e['n'] - $offset);
        return $t > Clock::now() ? $t : 0;
    }

    private function codeWait(): int
    {
        return $this->throttle(fn(array $d) => max(self::waitUntil($d[$this->ipKey()] ?? null), self::waitUntil($d['all'] ?? null, 15)));
    }

    private function codeFailed(): int
    {
        return $this->throttle(function (array $d, callable $save) {
            foreach ([$this->ipKey(), 'all'] as $k) $d[$k] = ['n' => (int)($d[$k]['n'] ?? 0) + 1, 'last' => Clock::now()];
            $save($d);
            return max(self::waitUntil($d[$this->ipKey()]), self::waitUntil($d['all'], 15));
        });
    }

    private function codeCleared(): void
    {
        $this->throttle(function (array $d, callable $save) {
            unset($d[$this->ipKey()]);
            $save($d);
        });
    }

    // ---------------------------------------------------------------- the installer's cookie

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/'), true);
    }

    private function cookieKey(): ?string
    {
        $h = $this->storedHash();
        return $h === null ? null : hash_hmac('sha256', 'ts-install|' . $this->paths->webroot, $h, true);
    }

    private function newSession(InstallResponse $res, bool $again): void
    {
        $s = ['v' => 1, 'sid' => bin2hex(random_bytes(8)), 'iat' => Clock::now(), 'exp' => Clock::now() + self::HOURS * 3600,
            're' => $again, 'k' => self::b64(random_bytes(32))];
        $payload = self::b64((string)json_encode($s));
        $res->cookie(self::COOKIE, $payload . '.' . self::b64(hash_hmac('sha256', $payload, (string)$this->cookieKey(), true)), self::HOURS * 3600);
        $res->cookie(self::FORM_COOKIE, '', -1);
    }

    private function session(): ?array
    {
        $key = $this->cookieKey();
        $v = (string)($this->req->cookies[self::COOKIE] ?? '');
        if ($key === null || !preg_match('/^([A-Za-z0-9_-]{20,600})\.([A-Za-z0-9_-]{43})$/', $v, $m)) return null;
        if (!hash_equals(self::b64(hash_hmac('sha256', $m[1], $key, true)), $m[2])) return null;
        $s = json_decode(self::unb64($m[1]), true);
        if (!is_array($s) || (int)($s['exp'] ?? 0) < Clock::now() || !preg_match('/^[a-f0-9]{16}$/', (string)($s['sid'] ?? ''))) return null;
        $s['key'] = self::unb64((string)($s['k'] ?? ''));
        return strlen($s['key']) === 32 ? $s : null;
    }

    /** The token in every step's form. */
    private function formToken(): string
    {
        return self::b64(hash_hmac('sha256', 'form|' . ($this->s['sid'] ?? ''), (string)$this->cookieKey(), true));
    }

    private function stepPost(): bool
    {
        return $this->req->method === 'POST' && $this->req->sameOrigin() && hash_equals($this->formToken(), (string)($this->req->post['f'] ?? ''));
    }

    private function hidden(): string
    {
        return '<input type="hidden" name="f" value="' . Http::esc($this->formToken()) . '">';
    }

    // ---------------------------------------------------------------- locks

    public static function isLocked(Paths $p): bool
    {
        return is_file($p->private . '/install.lock') || is_file($p->webroot . '/edit/.installed');
    }

    /**
     * Once set up, a reinstall also needs the launch kit unzipped into the website folder again
     * (cPanel → File Manager → Extract), which rewrites edit/install-code.hash. Only someone signed
     * in to the hosting can do that, so the setup code alone can't reopen a finished setup. The
     * file's change time can't be set by hand; the unzip counts for UNZIP_HOURS.
     */
    private function unzippedSinceLock(): bool
    {
        clearstatcache();
        $changed = (int)@filectime($this->codeHashFile());
        $lock = 0;
        foreach ([$this->paths->private . '/install.lock', $this->paths->webroot . '/edit/.installed'] as $f) {
            if (is_file($f)) $lock = max($lock, (int)filemtime($f));
        }
        return $changed > $lock && $changed > time() - self::UNZIP_HOURS * 3600;
    }

    /** Tell the people who get change alerts (John) about a setup on a finished site. Never stops the setup. */
    private function alertOwners(string $subject, array $lines): void
    {
        try {
            if (!is_file($this->paths->private . '/config.json')) return;
            $cfg = Config::load($this->paths);
            $to = (new People($cfg))->alertEmails();
            if ($to) (new Mailer($cfg))->send($to, $subject, $lines, null, 'alert');
        } catch (\Throwable $e) {
            Log::error('install alert: ' . $e->getMessage());
        }
    }

    private function lockTime(): int
    {
        $t = 0;
        $l = Json::read($this->paths->private . '/install.lock');
        if (is_array($l)) $t = (int)($l['at'] ?? 0);
        $w = $this->paths->webroot . '/edit/.installed';
        if (is_file($w)) $t = max($t, preg_match('/^at=(\d+)$/m', (string)@file_get_contents($w), $m) ? (int)$m[1] : (int)filemtime($w));
        return $t;
    }

    // ---------------------------------------------------------------- pages

    public static function layout(string $title, string $body): string
    {
        $e = [Http::class, 'esc'];
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<title>' . $e($title) . '</title>'
            . '<meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#0B0E13">'
            . '<link rel="icon" href="' . self::asset('icon-180.png') . '">'
            . '<link rel="stylesheet" href="' . self::asset('app.css') . '">'
            . '<link rel="stylesheet" href="' . self::asset('install.css') . '">'
            . '<script type="module" src="' . self::asset('install.js') . '"></script>'
            . '</head><body>' . $body . '</body></html>';
    }

    private static function asset(string $file): string
    {
        $f = dirname(__DIR__) . '/app/' . $file;
        return '/edit/app/' . $file . '?v=' . (is_file($f) ? substr(md5((string)filemtime($f) . filesize($f)), 0, 8) : '0');
    }

    public function page(string $title, string $inner, int $code = 200): InstallResponse
    {
        return InstallResponse::page(self::layout($title . ' · Website editor', '<main class="signin inst"><div class="signin-card inst-card">'
            . '<div class="signin-brand"><span class="dot"></span>Think Smart · Setting up the editor</div>' . $inner . '</div></main>'), $code);
    }

    private static function msg(?string $m, string $kind = 'bad'): string
    {
        return $m ? '<p class="msg ' . $kind . '" role="alert">' . Http::esc($m) . '</p>' : '';
    }

    private function notFound(): InstallResponse
    {
        return InstallResponse::page(self::layout('Not found · Website editor', '<main class="signin"><div class="signin-card"><div class="signin-brand"><span class="dot"></span>Think Smart · Website editor</div>'
            . '<h1>Not found</h1><p>That page isn\'t part of the editor.</p><p class="small"><a href="/edit/">Open the editor</a></p></div></main>'), 404);
    }

    /** "Step 2 of 5" */
    private function stepLine(string $step, ?InstallState $st): string
    {
        $steps = ['checks', 'people', 'leads', 'finish', 'done', 'sheet'];
        if ($st && ($st->d['askedMode'] ?? false)) array_splice($steps, 1, 0, ['content']);
        $i = array_search($step, $steps, true);
        return '<p class="inst-step">Step ' . ((int)$i + 1) . ' of ' . count($steps) . '</p>';
    }

    private function start(bool $locked, bool $again): InstallResponse
    {
        if ($again && !$locked) return InstallResponse::redirect('/edit/install');
        if (!$again && $locked) return $this->notFound();
        $req = $this->req;
        $title = $again ? 'Reinstall and keep my content' : 'Set up the website editor';
        $intro = $again
            ? '<p>This sets the website editor up again on this hosting and keeps what\'s in it: the words and photos, contracts, logins, where leads go, the leads, and History. Everyone signs in again afterwards.</p>'
            : '<p>This puts the website editor on this hosting. It takes about 10 minutes. Keep this page open until the end.</p>';
        $head = '<h1>' . Http::esc($title) . '</h1>' . $intro;
        if (!$req->https && !$this->dev) {
            $row = InstallChecks::https($req, false);
            return $this->page($title, $head . self::msg($row['text']) . '<p>' . Http::esc($row['action']) . '</p>');
        }
        if ($this->storedHash() === null) {
            return $this->page($title, $head . self::msg('The setup code file (edit/install-code.hash) is missing from the website folder.')
                . '<p>Upload the launch kit zip into public_html again and extract it there (cPanel → File Manager → Extract). Then reload this page.</p>');
        }
        if ($again && $locked && !$this->unzippedSinceLock()) {
            return $this->page($title, $head . '<p><b>First</b>, in cPanel → File Manager, upload the launch kit zip into public_html and <b>Extract</b> it there, letting it replace the files. (Only someone who can sign in to the hosting can do that, which keeps this page safe.)</p>'
                . '<p>Then reload this page within a day.</p>');
        }
        // already in a setup started here: carry on
        $cur = $this->session();
        if ($req->method === 'GET' && $cur !== null && (!$locked || (!empty($cur['re']) && (int)$cur['iat'] >= $this->lockTime()))) {
            return InstallResponse::redirect('/edit/install/checks');
        }
        $msg = null;
        if ($req->method === 'POST') {
            $f = (string)($req->cookies[self::FORM_COOKIE] ?? '');
            if (!$req->sameOrigin() || $f === '' || !hash_equals($f, (string)($req->post['f'] ?? ''))) {
                $msg = 'That page was open too long. Please try again.';
            } elseif (($wait = $this->codeWait()) > 0) {
                $msg = 'Too many tries. Try again at ' . Clock::time($wait) . '.';
            } elseif (self::codeMatches((string)($req->post['code'] ?? ''), (string)$this->storedHash())) {
                $this->codeCleared();
                $res = InstallResponse::redirect('/edit/install/checks');
                $this->newSession($res, $again);
                if ($again && $locked) {
                    $this->alertOwners('The website editor is being set up again', [
                        'Someone typed the setup code at ' . $this->siteUrl() . '/edit/install/reinstall on ' . Clock::local(Clock::now())->format('M j \a\t g:i a') . ' (Central time) to set the website editor up again, keeping its content.',
                        'If that was you or someone you asked, there\'s nothing to do. If nobody should have, change the GoDaddy password.',
                    ]);
                }
                return $res;
            } else {
                $t = $this->codeFailed();
                if (!empty($this->opt['slow'])) sleep(1);
                $msg = 'That setup code isn\'t right. Check it and try again.' . ($t ? ' Too many tries: try again at ' . Clock::time($t) . '.' : '');
            }
        }
        $f = (string)($req->cookies[self::FORM_COOKIE] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $f)) $f = Http::token();
        $res = $this->page($title, $head . self::msg($msg)
            . '<form method="post" action="/edit/install' . ($again ? '/reinstall' : '') . '" class="stack" data-once>'
            . '<input type="hidden" name="f" value="' . Http::esc($f) . '">'
            . '<label class="field"><span>Setup code</span><input name="code" id="code" class="code" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" required maxlength="80" enterkeyhint="go"></label>'
            . '<button class="btn primary big" type="submit">Start</button></form>'
            . '<p class="small">The setup code is in the launch kit, in John\'s Google Drive.</p>');
        $res->cookie(self::FORM_COOKIE, $f, 86400);
        return $res;
    }

    // ---------------------------------------------------------------- step 1: checks + private folder

    private function blocking(array $rows): bool
    {
        foreach ($rows as $r) if ($r['level'] === 'stop') return true;
        return false;
    }

    private static function rowHtml(array $r): string
    {
        $e = [Http::class, 'esc'];
        $mark = ['ok' => '✓', 'warn' => '!', 'stop' => '✕', 'info' => 'i'][$r['level']] ?? 'i';
        $word = ['ok' => 'fine', 'warn' => 'worth doing', 'stop' => 'needs fixing', 'info' => 'note'][$r['level']] ?? '';
        return '<li class="inst-check is-' . $e($r['level']) . '"><span class="inst-mark" aria-hidden="true">' . $mark . '</span><div>'
            . '<p class="inst-check-title">' . $e($r['title']) . '<span class="sr-only"> (' . $word . ')</span></p>'
            . '<p>' . $e($r['text']) . '</p>'
            . ($r['action'] !== '' ? '<p class="inst-do"><b>What to do:</b> ' . $e($r['action']) . '</p>' : '')
            . '</div></li>';
    }

    private function details(): string
    {
        $e = [Http::class, 'esc'];
        $li = '';
        foreach (InstallChecks::extras() as [$name, $on, $what]) $li .= '<li>' . $e($name) . ': ' . ($on ? 'on' : 'off') . ' (' . $e($what) . ')</li>';
        $li .= '<li>memory_limit ' . $e((string)ini_get('memory_limit')) . ', max_execution_time ' . $e((string)ini_get('max_execution_time')) . '</li>';
        $li .= '<li>Website folder: ' . $e($this->paths->webroot) . '</li><li>Private folder: ' . $e($this->paths->private) . '</li>';
        $li .= '<li>Hosting user: ' . $e($this->opt['user'] ?? InstallChecks::user($this->paths)) . ', server address: ' . $e($this->req->serverAddr ?: 'unknown') . '</li>';
        return '<details class="inst-details"><summary>Details for the developer</summary><ul>' . $li . '</ul></details>';
    }

    private function checks(?InstallState $st): InstallResponse
    {
        $rows = InstallChecks::all($this->paths, $this->req, $this->opt + ['dev' => $this->dev]);
        $msg = null;
        $priv = null;
        if ($this->req->method === 'POST') {
            if (!$this->stepPost()) {
                $msg = 'That page was open too long. Please try again.';
            } elseif ($this->blocking($rows)) {
                $msg = 'Fix the red items first, then press Check again.';
            } else {
                @set_time_limit(120);
                $priv = InstallChecks::makePrivate($this->paths, $this->req, $this->opt + ['dev' => $this->dev]);
                if ($priv['level'] !== 'stop') {
                    $st = new InstallState($this->paths, $this->s['sid']);
                    $st->d['private'] = ['at' => Clock::now(), 'dir' => $this->paths->private, 'tried' => count($priv['tried']),
                        'level' => $priv['level'], 'text' => $priv['text'], 'action' => $priv['action']];
                    $st->d['re'] = !empty($this->s['re']);
                    $st->save();
                    InstallState::tidy($this->paths, $this->s['sid']);
                    // proved: on to the next step; an orange note is shown here first
                    if ($priv['level'] === 'ok') return InstallResponse::redirect('/edit/install' . $this->next($st));
                }
            }
        }
        $made = $st && !empty($st->d['private']);
        if ($priv === null) {
            $priv = $made
                ? InstallChecks::row('private', (string)($st->d['private']['level'] ?? 'ok'), 'Private folder', (string)$st->d['private']['text'], (string)($st->d['private']['action'] ?? ''))
                : InstallChecks::row('private', 'info', 'Private folder', 'Made in the next step, and then tested: it must not open from the web.');
        }
        $list = '';
        foreach ($rows as $r) $list .= self::rowHtml($r);
        $list .= self::rowHtml($priv);
        $stop = $this->blocking($rows) || $priv['level'] === 'stop';
        $again = '<form method="post" action="/edit/install/checks" class="stack" data-once data-busy="Making the private folder…">' . $this->hidden();
        if ($this->blocking($rows)) {
            $buttons = '<p class="inst-note">Fix the red items, then press Check again.</p><a class="btn primary big" href="/edit/install/checks">Check again</a>';
        } elseif ($made && $priv['level'] !== 'stop') {
            $buttons = '<a class="btn primary big" href="/edit/install' . $this->next($st) . '">Next</a>'
                . $again . '<button class="btn inst-wide" type="submit">Test the private folder again</button></form>';
        } else {
            $buttons = $again . '<button class="btn primary big" type="submit">' . ($priv['level'] === 'stop' ? 'Try the private folder again' : 'Next: make the private folder') . '</button></form>'
                . '<a class="btn" href="/edit/install/checks">Check again</a>';
        }
        return $this->page('Check the hosting', $this->stepLine('checks', $st) . '<h1>Check the hosting</h1>' . self::msg($msg)
            . '<p>' . ($stop ? 'Each red item says what to do.' : 'Everything the editor needs is here.') . '</p>'
            . '<ul class="inst-checks">' . $list . '</ul>' . $buttons . $this->details());
    }

    /** The step still to do. */
    private function next(InstallState $st): string
    {
        if (empty($st->d['private'])) return '/checks';
        if (empty($st->d['mode'])) {
            if (!empty($this->s['re']) || !empty($st->d['re'])) $st->d['mode'] = 'keep';
            elseif (!$this->hasData()) $st->d['mode'] = 'fresh';
            else { $st->d['askedMode'] = true; $st->save(); return '/content'; }
            $st->save();
        }
        if (!isset($st->d['people'])) return '/people';
        if (!isset($st->d['leadEmail'])) {
            if ($st->d['mode'] === 'keep' && $this->leadAddresses() !== []) {
                $st->d['leadEmail'] = '';
                $st->save();
            } else {
                return '/leads';
            }
        }
        return '/finish';
    }

    private function hasData(): bool
    {
        $p = $this->paths->private;
        return is_file("$p/content.json") || is_dir("$p/history") || (new People(Config::load($this->paths)))->active() !== [];
    }

    /** Where leads go now (keep mode), from settings.json. */
    private function leadAddresses(): array
    {
        if (!class_exists(LeadSettings::class) || !is_file($this->paths->private . '/settings.json')) return [];
        $out = [];
        foreach (LeadSettings::load(Config::load($this->paths))['leadTo'] as $a) {
            if (is_array($a) && !empty($a['email'])) $out[] = ['email' => (string)$a['email'], 'state' => (string)($a['state'] ?? '')];
        }
        return $out;
    }

    // ---------------------------------------------------------------- step: content from before

    private function content(InstallState $st): InstallResponse
    {
        if (empty($st->d['askedMode'])) return InstallResponse::redirect('/edit/install' . $this->next($st));
        $msg = null;
        if ($this->req->method === 'POST') {
            $choice = (string)($this->req->post['choice'] ?? '');
            if (!$this->stepPost()) $msg = 'That page was open too long. Please try again.';
            elseif ($choice === 'fresh' && empty($this->req->post['sure'])) $msg = 'To start over, tick the box under it too.';
            elseif ($choice === 'keep' || $choice === 'fresh') {
                if (($st->d['mode'] ?? '') !== $choice) unset($st->d['people'], $st->d['leadEmail']);
                $st->d['mode'] = $choice;
                $st->d['startOver'] = $choice === 'fresh';
                $st->save();
                return InstallResponse::redirect('/edit/install' . $this->next($st));
            } else {
                $msg = 'Pick one of the two.';
            }
        }
        $when = is_file($this->paths->content()) ? Clock::when((int)filemtime($this->paths->content())) : '';
        return $this->page('The content from before', $this->stepLine('content', $st) . '<h1>The content from before</h1>' . self::msg($msg)
            . '<p>This hosting already has the editor\'s content' . ($when !== '' ? ' (last saved ' . Http::esc($when) . ')' : '') . '.</p>'
            . '<form method="post" action="/edit/install/content" class="stack" data-once>' . $this->hidden()
            . '<label class="inst-choice"><input type="radio" name="choice" value="keep" checked><span><b>Keep it</b> (best): the words, photos, contracts, logins and History stay as they are.</span></label>'
            . '<label class="inst-choice"><input type="radio" name="choice" value="fresh"><span><b>Start over</b> from the original website. What\'s here is put aside in the private folder, not deleted. Leads stay.</span></label>'
            . '<label class="check"><input type="checkbox" name="sure" value="1"> I want to start over (only for "Start over")</label>'
            . '<button class="btn primary big" type="submit">Next</button></form>');
    }

    // ---------------------------------------------------------------- step: who can edit

    /** @return array<int, array<string,string>> person index => field => problem */
    public static function peopleProblems(array $list): array
    {
        $bad = [];
        $site = strtolower(strstr(self::SITE_EMAIL, '@', true));
        foreach ($list as $i => $pp) {
            $name = trim((string)($pp['name'] ?? ''));
            $user = trim((string)($pp['username'] ?? ''));
            $mail = strtolower(trim((string)($pp['email'] ?? '')));
            if ($name === '') $bad[$i]['name'] = 'Add their name.';
            elseif (strlen($name) > 60) $bad[$i]['name'] = 'Use a shorter name.';
            if (str_contains($user, '@')) $bad[$i]['username'] = 'The username is private, so not an email address. Pick something like jnelson-77.';
            elseif (($e = People::usernameProblem($user)) !== null) $bad[$i]['username'] = $e;
            elseif (in_array(strtolower($user), [$site, 'thinksmart', 'admin', 'editor', 'john', 'muneerah'], true)) $bad[$i]['username'] = 'That username is easy to guess. Add something only they know, like ' . strtolower($user) . '-' . random_int(20, 99) . '.';
            if (!preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/', $mail) || strlen($mail) > 120) $bad[$i]['email'] = 'That email address doesn\'t look right.';
        }
        if (count($list) === 2) {
            if (!isset($bad[1]['username']) && strtolower(trim((string)$list[0]['username'])) === strtolower(trim((string)$list[1]['username']))) $bad[1]['username'] = 'Each person needs their own username.';
            if (!isset($bad[1]['email']) && strtolower(trim((string)$list[0]['email'])) === strtolower(trim((string)$list[1]['email']))) $bad[1]['email'] = 'Each person needs their own email (sign-in codes go there).';
        }
        return $bad;
    }

    private function people(InstallState $st): InstallResponse
    {
        $e = [Http::class, 'esc'];
        $existing = $st->d['mode'] === 'keep' ? (new People(Config::load($this->paths)))->active() : [];
        $msg = null;
        if ($existing) {
            if ($this->req->method === 'POST') {
                if (!$this->stepPost()) $msg = 'That page was open too long. Please try again.';
                else {
                    $st->d['people'] = ['keep' => true, 'newRescue' => !empty($this->req->post['newRescue']), 'list' => []];
                    $st->save();
                    return InstallResponse::redirect('/edit/install' . $this->next($st));
                }
            }
            $li = '';
            foreach ($existing as $pp) {
                $li .= '<li><b>' . $e((string)$pp['name']) . '</b>, username ' . $e((string)$pp['username'])
                    . (($pp['hash'] ?? '') === '' ? ' (hasn\'t set a password yet: gets a set-your-password code)' : ' (same password as before)') . '</li>';
            }
            return $this->page('Who can edit', $this->stepLine('people', $st) . '<h1>Who can edit the website</h1>' . self::msg($msg)
                . '<p>These people keep their logins:</p><ul class="inst-list">' . $li . '</ul>'
                . '<form method="post" action="/edit/install/people" class="stack" data-once>' . $this->hidden()
                . '<label class="check"><input type="checkbox" name="newRescue" value="1"> Make new rescue codes and a new emergency sheet (the old sheet\'s codes stop working)</label>'
                . '<button class="btn primary big" type="submit">Next</button></form>');
        }
        $list = $st->d['people']['list'] ?? [['name' => 'John', 'username' => '', 'email' => ''], ['name' => 'Muneerah', 'username' => '', 'email' => '']];
        $bad = [];
        if ($this->req->method === 'POST') {
            $in = (array)($this->req->post['p'] ?? []);
            $list = [];
            for ($i = 0; $i < 2; $i++) {
                $row = is_array($in[$i] ?? null) ? $in[$i] : [];
                $list[] = ['name' => trim((string)($row['name'] ?? '')), 'username' => trim((string)($row['username'] ?? '')), 'email' => strtolower(trim((string)($row['email'] ?? '')))];
            }
            if (!$this->stepPost()) $msg = 'That page was open too long. Please try again.';
            elseif ($bad = self::peopleProblems($list)) $msg = 'Check the boxes marked below.';
            else {
                foreach ($list as $i => $pp) $list[$i]['alerts'] = $i === 0;
                $st->d['people'] = ['keep' => false, 'newRescue' => true, 'list' => $list];
                $st->save();
                return InstallResponse::redirect('/edit/install' . $this->next($st));
            }
        }
        $blocks = '';
        foreach ([0 => ['First person', 'Gets an email when someone changes something important.'], 1 => ['Second person', '']] as $i => [$legend, $note]) {
            $pp = $list[$i] ?? ['name' => '', 'username' => '', 'email' => ''];
            $err = fn(string $k) => isset($bad[$i][$k]) ? '<small class="bad-text" role="alert">' . $e($bad[$i][$k]) . '</small>' : '';
            $inv = fn(string $k) => isset($bad[$i][$k]) ? ' aria-invalid="true"' : '';
            $blocks .= '<fieldset class="inst-person-form"><legend>' . $e($legend) . '</legend>' . ($note !== '' ? '<p class="small">' . $e($note) . '</p>' : '')
                . '<label class="field"><span>Name</span><input name="p[' . $i . '][name]" value="' . $e((string)$pp['name']) . '" autocomplete="off" autocorrect="off" required maxlength="60"' . $inv('name') . '>' . $err('name') . '</label>'
                . '<label class="field"><span>Username</span><input name="p[' . $i . '][username]" value="' . $e((string)$pp['username']) . '" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" required maxlength="32"' . $inv('username') . '>'
                . $err('username') . '<small>Private: not the business email. 3 to 32 letters or numbers (dots, dashes and _ are fine).</small></label>'
                . '<label class="field"><span>Their own email</span><input name="p[' . $i . '][email]" type="email" inputmode="email" value="' . $e((string)$pp['email']) . '" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" required maxlength="120"' . $inv('email') . '>'
                . $err('email') . '<small>Their sign-in codes go here.</small></label></fieldset>';
        }
        return $this->page('Who can edit', $this->stepLine('people', $st) . '<h1>Who can edit the website</h1>' . self::msg($msg)
            . '<p>Each person gets their own login. Passwords aren\'t typed here: at the end, each person sets theirs on their own iPhone, so the iPhone saves it.</p>'
            . '<form method="post" action="/edit/install/people" class="stack" data-once novalidate>' . $this->hidden() . $blocks
            . '<button class="btn primary big" type="submit">Next</button></form>'
            . '<p class="small"><a href="/edit/install/checks">Back</a></p>');
    }

    // ---------------------------------------------------------------- step: where leads are emailed

    private function leads(InstallState $st): InstallResponse
    {
        $e = [Http::class, 'esc'];
        $msg = null;
        $email = (string)($st->d['leadEmail'] ?? '') ?: (class_exists(LeadSettings::class) ? LeadSettings::DEFAULT_TO : self::SITE_EMAIL);
        if ($this->req->method === 'POST') {
            $email = strtolower(trim((string)($this->req->post['lead'] ?? '')));
            if (!$this->stepPost()) $msg = 'That page was open too long. Please try again.';
            elseif (!preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/', $email) || strlen($email) > 120) $msg = 'That email address doesn\'t look right.';
            else {
                $st->d['leadEmail'] = $email;
                $st->save();
                return InstallResponse::redirect('/edit/install' . $this->next($st));
            }
        }
        $old = self::oldLeadsSummary($this->paths);
        $oldText = $old['lines'] > 0
            ? '<p class="inst-note">The old website form kept a copy of every lead (' . $old['lines'] . ' in all). The ' . $old['recent'] . ' from the last 90 days move into the editor\'s Leads screen, and then the old copies are deleted.</p>'
            : '';
        return $this->page('Where leads go', $this->stepLine('leads', $st) . '<h1>Where leads are emailed</h1>' . self::msg($msg)
            . '<p>Claims, messages and chats from the website are emailed here, and also kept in the editor for 90 days.</p>'
            . '<form method="post" action="/edit/install/leads" class="stack" data-once>' . $this->hidden()
            . '<label class="field"><span>Email address for new leads</span><input name="lead" type="email" inputmode="email" value="' . $e($email) . '" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" required maxlength="120">'
            . '<small>A test lead goes there with a button to confirm it. The setup can finish before anyone taps it.</small></label>'
            . $oldText . '<button class="btn primary big" type="submit">Next</button></form>'
            . '<p class="small"><a href="/edit/install/people">Back</a></p>');
    }

    // ---------------------------------------------------------------- step: set it up

    private function siteUrl(): string
    {
        $h = $this->req->safeHost();
        if ($h === '') $h = 'www.thinksmartpublicadjusting.com';
        return ($this->req->https || !$this->dev ? 'https' : 'http') . '://' . $h;
    }

    private function finish(InstallState $st): InstallResponse
    {
        $e = [Http::class, 'esc'];
        $msg = null;
        if ($this->req->method === 'POST') {
            if (!$this->stepPost()) $msg = 'That page was open too long. Please try again.';
            else {
                ignore_user_abort(true);
                @set_time_limit(300);
                try {
                    Lock::with($this->paths, 'install', fn() => $this->work($st));
                    return InstallResponse::redirect('/edit/install/done');
                } catch (\Throwable $ex) {
                    Log::error('install finish: ' . get_class($ex) . ': ' . $ex->getMessage() . ' @' . $ex->getFile() . ':' . $ex->getLine());
                    $st->d['error'] = $ex->getMessage();
                    $st->save();
                    $msg = 'Something stopped the setup partway. Nothing is lost: press Set it up again to carry on from there.';
                }
            }
        }
        $site = $this->siteUrl();
        $rows = '<dt>Website</dt><dd>' . $e($site) . '</dd><dt>Website editor</dt><dd>' . $e($site) . '/edit</dd>';
        $keep = $st->d['mode'] === 'keep';
        $rows .= '<dt>Content</dt><dd>' . ($keep && is_file($this->paths->content()) ? 'Kept as it is' : 'The website as it is today') . '</dd>';
        $pl = '';
        if (!empty($st->d['people']['keep'])) {
            foreach ((new People(Config::load($this->paths)))->active() as $pp) {
                $pl .= '<b>' . $e((string)$pp['name']) . '</b>, username <span class="inst-code">' . $e((string)$pp['username']) . '</span>'
                    . (($pp['hash'] ?? '') === '' ? ' (gets a set-your-password code)' : ' (same password)') . '<br>';
            }
            $pl .= !empty($st->d['people']['newRescue']) ? 'New rescue codes for a new emergency sheet' : 'The rescue codes stay the same';
        } else {
            foreach ($st->d['people']['list'] as $i => $pp) {
                $pl .= '<b>' . $e($pp['name']) . '</b>, username <span class="inst-code">' . $e($pp['username']) . '</span>, ' . $e($pp['email']) . ($i === 0 ? ' (gets the change alerts)' : '') . '<br>';
            }
        }
        $rows .= '<dt>Who can edit</dt><dd>' . $pl . '</dd>';
        if (($st->d['leadEmail'] ?? '') !== '') {
            $rows .= '<dt>Leads go to</dt><dd>' . $e($st->d['leadEmail']) . ' (a test lead with a confirm button goes there)</dd>';
        } else {
            $rows .= '<dt>Leads go to</dt><dd>' . $e(implode(', ', array_column($this->leadAddresses(), 'email')) ?: 'the same address as before') . '</dd>';
        }
        $old = self::oldLeadsSummary($this->paths);
        if ($old['lines'] > 0) $rows .= '<dt>Old lead copies</dt><dd>' . $old['recent'] . ' from the last 90 days move into the editor; then the old copies are deleted</dd>';
        $zips = array_values(array_filter(scandir($this->paths->webroot) ?: [], fn($f) => (bool)preg_match(self::KIT_PATTERN, $f)));
        if ($zips) $rows .= '<dt>Setup zip</dt><dd>' . $e(implode(', ', $zips)) . ' is deleted from the website folder</dd>';
        if ($msg === null && !empty($st->d['error'])) $msg = 'The last try stopped partway. Nothing is lost: press Set it up again to carry on from there.';
        $err = !empty($st->d['error']) ? '<details class="inst-details"><summary>Details for the developer</summary><p class="pre">' . $e((string)$st->d['error']) . '</p></details>' : '';
        return $this->page('Set it up', $this->stepLine('finish', $st) . '<h1>Ready to set it up</h1>' . self::msg($msg)
            . '<dl class="inst-summary">' . $rows . '</dl>'
            . '<form method="post" action="/edit/install/finish" class="stack" data-once data-busy="Setting it up… keep this page open">' . $this->hidden()
            . '<button class="btn primary big" type="submit">Set it up</button></form>'
            . '<p class="small">This takes up to a minute.' . ($st->d['mode'] === 'fresh' ? ' <a href="/edit/install/leads">Back</a>' : '') . '</p>' . $err);
    }

    /** The setup itself. Every part is done once: after an interruption, it carries on. */
    private function work(InstallState $st): void
    {
        $p = $this->paths;
        $keep = $st->d['mode'] === 'keep';
        $key = (string)$this->s['key'];
        if ($st->done('finished')) return;

        if (!$st->done('aside') && !empty($st->d['startOver'])) {
            $st->d['asideTo'] = self::putAside($p);
            $st->mark('aside');
        }
        if (!$st->done('config')) {
            $old = Json::read($p->private . '/config.json', []);
            $old = is_array($old) ? $old : [];
            Json::write($p->private . '/config.json', array_merge($old, [
                'secret' => bin2hex(random_bytes(32)), 'siteUrl' => $this->siteUrl(), 'mail' => $this->opt['mail'], 'dev' => $this->dev,
            ]));
            @chmod($p->private . '/config.json', 0600);
            // sign-ins, codes and email links made with the old secret can't work any more
            foreach (glob($p->private . '/sessions/*.json') ?: [] as $f) @unlink($f);
            foreach (['codes.json', 'tokens.json', 'throttle.json'] as $f) if (is_file($p->private . '/' . $f)) @unlink($p->private . '/' . $f);
            $st->mark('config');
        }
        $cfg = Config::load($p);
        if (!$st->done('content')) {
            $store = new ContentStore($p);
            if ($keep && (is_file($p->content()) || is_file($p->content() . '.good'))) $store->load();
            else {
                $seed = json_decode((string)file_get_contents($p->app . '/seed/content.json'), true);
                if (!is_array($seed) || !isset($seed['site'])) throw new BuildError('The starting content (edit/seed/content.json) is unreadable');
                $store->save($seed);
            }
            $st->mark('content');
        }
        if (!$st->done('people')) {
            $people = new People($cfg);
            if (empty($st->d['people']['keep'])) {
                foreach ($st->d['people']['list'] as $i => $pp) {
                    $found = $people->findLogin($pp['username']);
                    if ($found && ($found['hash'] ?? '') === '' && strtolower($found['username']) === strtolower($pp['username'])) {
                        $person = $people->update($found['id'], ['name' => $pp['name'], 'email' => $pp['email'], 'alerts' => $i === 0]);
                    } else {
                        $person = $people->add($pp['name'], $pp['username'], $pp['email'], null, $i === 0);
                    }
                    $st->d['people']['list'][$i]['id'] = $person['id'];
                }
                $people->save();
            }
            $st->mark('people');
        }
        if (!$st->done('codes')) {
            // rescue codes: 2 each (one printed, one for the Passwords app); a set-your-password
            // code (24 hours) for everyone who has no password yet
            $people = new People($cfg);
            $codes = new Codes($cfg);
            $out = [];
            foreach ($people->active() as $pp) {
                $new = empty($st->d['people']['keep']) || !empty($st->d['people']['newRescue']) || empty($pp['rescue']);
                $set = ($pp['hash'] ?? '') === '';
                if (!$new && !$set) continue;
                $row = ['id' => $pp['id'], 'name' => $pp['name'], 'username' => $pp['username'], 'email' => $pp['email'], 'rescue' => [], 'code' => null, 'until' => null];
                if ($new) $row['rescue'] = $people->newRescueCodes($pp['id']);
                if ($set) { $row['code'] = $codes->issue($pp['id'], 'reset'); $row['until'] = Clock::now() + Codes::RESET_HOURS * 3600; }
                $out[$pp['id']] = $row;
            }
            $st->putCodes($key, ['people' => $out]);
            $people->save();
            $st->mark('codes');
        }
        if (!$st->done('build')) {
            $live = (new LiveContent($cfg, Schema::load($p)))->load();
            $build = new Build($p);
            $res = $build->build($live, self::contractFiles($p, $live));
            Lock::with($p, 'publish', fn() => Journal::publish($p, $res));
            $build->prune(5);
            $used = [];
            History::collectMedia($live, $used);
            $st->d['missingPhotos'] = array_values(array_filter(array_keys($used), fn($m) => !is_file($p->webroot . '/' . $m)));
            $st->mark('build');
        }
        if (!$st->done('settle')) {
            (new Publisher($cfg, new People($cfg)))->settle(); // the launch-day History entry (kept forever)
            $st->mark('settle');
        }
        if (!$st->done('mail')) {
            $mailer = new Mailer($cfg);
            foreach ($st->codes($key)['people'] ?? [] as $row) {
                if (empty($row['code'])) continue;
                $st->d['mailed'][$row['id']] = $mailer->send([$row['email']], 'Set your password for the website editor', [
                    'Hi ' . $row['name'] . ', you can now change the Think Smart website yourself.',
                    'Your code is ' . $row['code'] . '. It works for 24 hours.',
                    'On your iPhone, tap the button below (it opens in Safari). Type your username and this code, then tap New password and let your iPhone suggest a strong one and save it. After that, Face ID fills it in.',
                ], ['Set my password', $cfg->siteUrl() . '/edit/?code'], 'code');
            }
            $st->mark('mail');
        }
        if (!$st->done('oldLeads')) {
            $progress = (array)($st->d['oldProgress'] ?? []);
            $st->d['oldLeads'] = self::migrateOldLeads($cfg, $progress, function (array $pr) use ($st) { $st->d['oldProgress'] = $pr; $st->save(); });
            $st->mark('oldLeads');
        }
        if (!$st->done('leadEmail')) {
            $by = null;
            foreach ((new People($cfg))->active() as $pp) if (!empty($pp['alerts'])) { $by = $pp['id']; break; }
            $sent = [];
            if (class_exists(LeadSettings::class) && method_exists(LeadSettings::class, 'addPending')) {
                if (($st->d['leadEmail'] ?? '') !== '') $sent[] = $st->d['leadEmail'];
                // still waiting from before: the old link stopped working with the new secret, so send a new one
                elseif ($keep) foreach ($this->leadAddresses() as $a) if ($a['state'] !== 'confirmed') $sent[] = $a['email'];
                foreach ($sent as $addr) LeadSettings::addPending($cfg, $addr, $by);
            } else {
                $st->d['notes'][] = 'The lead email address isn\'t set yet: set it in the editor (More).';
            }
            $st->d['leadSent'] = $sent;
            $st->mark('leadEmail');
        }
        if (!$st->done('readme')) {
            Files::writeAtomic($p->private . '/README-developer.md', self::developerReadme($p, $cfg));
            $st->mark('readme');
        }
        if (!$st->done('pristine')) {
            if (method_exists(Health::class, 'snapshotPristine')) Health::snapshotPristine($cfg);
            $st->mark('pristine');
        }
        if (!$st->done('zips')) {
            $st->d['zipsGone'] = self::removeLeftoverZips($p);
            $st->mark('zips');
        }
        // the locks: the private one, and one in the website folder that holds even if the
        // private folder is ever moved or lost
        $at = Clock::now();
        Json::write($p->private . '/install.lock', ['at' => $at, 'mode' => $st->d['mode'], 'siteUrl' => $cfg->siteUrl(), 'sid' => $st->d['sid']]);
        try {
            Files::writeAtomic($p->webroot . '/edit/.installed', 'The website editor was set up here on ' . Clock::local($at)->format('M j, Y g:i a') . " (Central time).\n"
                . "/edit/install stays closed while this file or install.lock in the private folder is here.\n"
                . "To set it up again and keep the content: unzip the launch kit here again, then open /edit/install/reinstall and type the setup code.\nat=$at\n");
            @chmod($p->webroot . '/edit/.installed', 0644);
        } catch (\Throwable $ex) {
            $st->d['notes'][] = 'The lock file in the website folder (edit/.installed) couldn\'t be written; the one in the private folder holds.';
        }
        if (!empty($st->d['re']) && !$st->done('alerted')) {
            $this->alertOwners('The website editor was set up again', array_values(array_filter([
                'The website editor was set up again on ' . Clock::local($at)->format('M j \a\t g:i a') . ' (Central time). The words, photos, logins and leads were kept; everyone signs in again.',
                !empty($st->d['people']['newRescue']) ? 'New rescue codes were made, so the codes on the old emergency sheet don\'t work any more.' : null,
                'If nobody should have done this, change the GoDaddy password.',
            ])));
            $st->mark('alerted');
        }
        InstallState::tidy($p, $st->d['sid'], 0);
        unset($st->d['error']);
        $st->d['finished'] = $at;
        $st->mark('finished');
    }

    /** "Start over": put the private files from before into before-install-<time>/ (nothing is deleted). */
    private static function putAside(Paths $p): string
    {
        $to = 'before-install-' . gmdate('Ymd-His', Clock::now());
        @mkdir($p->private . '/' . $to, 0700, true);
        foreach (self::ASIDE as $f) {
            if (file_exists($p->private . '/' . $f)) @rename($p->private . '/' . $f, $p->private . "/$to/$f");
        }
        return $to;
    }

    /** Contracts whose chosen version isn't at its permanent address (as Publisher does). */
    private static function contractFiles(Paths $p, array $c): array
    {
        $out = [];
        foreach ($c['states'] ?? [] as $st) {
            $target = (string)($st['pdf'] ?? '');
            if ($target === '') continue;
            $v = (string)($st['pdfV'] ?? '');
            if ($v === '') foreach (Contracts::versions($p, (string)($st['code'] ?? '')) as $ver) if (!empty($ver['first'])) $v = (string)$ver['v'];
            if ($v === '') continue;
            $src = Contracts::file($p, $v);
            if (is_file($src) && Files::sha($p->webroot . '/' . $target) !== hash_file('sha256', $src)) $out[$target] = $src;
        }
        return $out;
    }

    // ---------------------------------------------------------------- the old lead logs

    public static function oldLeadsDir(Paths $p): string
    {
        return dirname($p->webroot) . '/thinksmart-leads';
    }

    /** @return array{files:int, lines:int, recent:int} */
    public static function oldLeadsSummary(Paths $p): array
    {
        $r = ['files' => 0, 'lines' => 0, 'recent' => 0];
        $cut = Clock::now() - 90 * 86400;
        foreach (glob(self::oldLeadsDir($p) . '/leads-*.log') ?: [] as $f) {
            $r['files']++;
            $h = @fopen($f, 'rb');
            if ($h === false) continue;
            while (($line = fgets($h)) !== false) {
                $rec = json_decode($line, true);
                if (!is_array($rec)) continue;
                $r['lines']++;
                $at = isset($rec['received']) ? strtotime((string)$rec['received']) : false;
                if ($at !== false && $at >= $cut) $r['recent']++;
            }
            fclose($h);
        }
        return $r;
    }

    /**
     * Move the last 90 days of the old lead logs (dirname(webroot)/thinksmart-leads/
     * leads-YYYY-MM.log, written by the lead mailer before the editor) into the editor's
     * Leads, then delete the logs and the folder. Nothing is deleted unless it was moved.
     * $progress (file => lines done) is saved after every lead, so a re-run doesn't add twice.
     */
    public static function migrateOldLeads(Config $cfg, array &$progress, ?callable $saved = null): array
    {
        $dir = self::oldLeadsDir($cfg->paths);
        $r = ['moved' => 0, 'old' => 0, 'unreadable' => 0, 'deleted' => 0, 'kept' => [], 'folderGone' => !is_dir($dir), 'skipped' => false];
        if (!is_dir($dir)) return $r;
        if (!class_exists(Leads::class) || !method_exists(Leads::class, 'import')) { $r['skipped'] = true; return $r; }
        $cut = Clock::now() - 90 * 86400;
        $files = glob($dir . '/leads-*.log') ?: [];
        sort($files);
        foreach ($files as $f) {
            $name = basename($f);
            $h = @fopen($f, 'rb');
            if ($h === false) { $r['kept'][] = $name; continue; }
            $done = (int)($progress[$name] ?? 0);
            $i = 0;
            while (($line = fgets($h)) !== false) {
                $i++;
                if ($i <= $done || trim($line) === '') continue;
                $rec = json_decode($line, true);
                if (!is_array($rec)) $r['unreadable']++;
                else {
                    $at = isset($rec['received']) ? strtotime((string)$rec['received']) : false;
                    if ($at !== false && $at >= $cut) { Leads::import($cfg, $rec); $r['moved']++; }
                    else $r['old']++;
                }
                $progress[$name] = $i;
                if ($saved) $saved($progress);
            }
            fclose($h);
            if (@unlink($f)) $r['deleted']++;
            else $r['kept'][] = $name;
        }
        if (!array_diff(scandir($dir) ?: [], ['.', '..', '.DS_Store'])) {
            if (is_file($dir . '/.DS_Store')) @unlink($dir . '/.DS_Store');
            $r['folderGone'] = @rmdir($dir);
        }
        return $r;
    }

    /** The launch kit's zip, if it's still in the website folder (only names made by make-zip.php). */
    public static function removeLeftoverZips(Paths $p): array
    {
        $gone = [];
        foreach (scandir($p->webroot) ?: [] as $f) {
            if (!preg_match(self::KIT_PATTERN, $f)) continue;
            $full = $p->webroot . '/' . $f;
            if (is_file($full) && !is_link($full) && @unlink($full)) $gone[] = $f;
        }
        return $gone;
    }

    // ---------------------------------------------------------------- done: passwords, then the sheet

    private function codesFor(InstallState $st): ?array
    {
        if (!$st->hasCodes()) return null;
        $c = $st->codes((string)$this->s['key']);
        return isset($c['people']) ? $c : null;
    }

    private function done(InstallState $st): InstallResponse
    {
        $e = [Http::class, 'esc'];
        $codes = $this->codesFor($st);
        $cfg = Config::load($this->paths);
        $site = $cfg->siteUrl();
        $host = (string)preg_replace('~^https?://(www\.)?~', '', $site); // shorter to type on a phone; it's the same website
        $body = '<h1>The editor is set up</h1>';
        if ($codes === null) {
            $body .= self::msg('The codes can only be shown in the browser that ran the setup, until its emergency sheet is done.', 'bad');
            return $this->page('Set up', $body . '<p><a class="btn" href="/edit/">Open the editor</a></p>');
        }
        $people = $codes['people'];
        $withCode = array_filter($people, fn($r) => !empty($r['code']));
        if ($withCode) {
            $body .= '<p>Now each person sets their own password <b>on their own iPhone</b>, so the iPhone saves it and Face ID fills it in from then on.</p>';
            foreach ($withCode as $r) {
                $code = substr((string)$r['code'], 0, 3) . ' ' . substr((string)$r['code'], 3);
                $mailed = !empty($st->d['mailed'][$r['id']]);
                $body .= '<section class="inst-person"><h2>' . $e($r['name']) . '</h2><ol class="inst-howto">'
                    . '<li>On ' . $e($r['name']) . '\'s iPhone, open <b>' . $e($host) . '/edit/?code</b> in Safari' . ($mailed ? ', or tap the button in the email “Set your password for the website editor”' : '') . '.</li>'
                    . '<li>Type the username <b>' . $e($r['username']) . '</b> and this code:<p class="code-big">' . $e($code) . '</p></li>'
                    . '<li>Tap <b>New password</b>, let the iPhone suggest a strong password, then tap <b>Save new password and sign in</b>. Let the iPhone save it.</li></ol>'
                    . '<p class="small">The code works until ' . $e(Clock::when((int)$r['until'])) . '.' . ($mailed ? ' It was also emailed to ' . $e($r['email']) . ' (check Spam).' : ' The email to ' . $e($r['email']) . ' didn\'t go out, so use the code above.') . '</p></section>';
            }
        }
        foreach ((new People($cfg))->active() as $pp) {
            if (!isset($withCode[$pp['id']]) && ($pp['hash'] ?? '') !== '') $body .= '<p>' . $e((string)$pp['name']) . ' signs in with the same password as before.</p>';
        }
        $notes = [];
        foreach ((array)($st->d['leadSent'] ?? []) as $addr) {
            $notes[] = 'A test lead was sent to ' . $addr . '. Open it and tap “Yes, send leads here”. Leads are kept safe in the editor meanwhile.';
        }
        $ol = $st->d['oldLeads'] ?? [];
        if (!empty($ol['skipped'])) $notes[] = 'The old lead copies were left where they are (thinksmart-leads beside the website folder).';
        elseif (($ol['moved'] ?? 0) + ($ol['old'] ?? 0) > 0) {
            $notes[] = ($ol['moved'] ?? 0) . ' leads from the old form copies are now in the editor (Leads)' . (($ol['deleted'] ?? 0) > 0 ? ', and the old copies were deleted.' : '.');
        }
        if (!empty($ol['kept'])) $notes[] = 'These old lead files couldn\'t be deleted: ' . implode(', ', $ol['kept']) . '.';
        foreach ((array)($st->d['zipsGone'] ?? []) as $z) $notes[] = "The setup zip ($z) was deleted from the website folder.";
        if (!empty($st->d['missingPhotos'])) {
            $notes[] = count($st->d['missingPhotos']) . ' photos the website uses are missing from the website folder (media/u). Put them back from the latest "Download a copy" (website/media/u).';
        }
        foreach ((array)($st->d['notes'] ?? []) as $n) $notes[] = $n;
        if ($notes) $body .= '<ul class="inst-list">' . implode('', array_map(fn($n) => '<li>' . $e($n) . '</li>', $notes)) . '</ul>';
        $body .= '<a class="btn primary big" href="/edit/install/sheet">Next: the emergency sheet</a>';
        return $this->page('Set up', $this->stepLine('done', $st) . $body);
    }

    private function sheet(InstallState $st): InstallResponse
    {
        $e = [Http::class, 'esc'];
        $codes = $this->codesFor($st);
        if ($codes === null) return $this->notFound();
        if ($this->req->method === 'POST' && ($this->req->post['do'] ?? '') === 'close') {
            if (!$this->stepPost()) return InstallResponse::redirect('/edit/install/sheet');
            $st->forget();
            $res = $this->page('All set', '<h1>All set</h1><p>The website editor is ready, and this setup page is now closed.</p>'
                . '<p>If a rescue code is ever lost, new ones can be made in the editor: More → People.</p>'
                . '<p><a class="btn primary big" href="/edit/">Open the editor</a></p>');
            $res->cookie(self::COOKIE, '', -1);
            return $res;
        }
        $cfg = Config::load($this->paths);
        $site = $cfg->siteUrl();
        $made = Clock::local(Clock::now())->format('M j, Y');
        $rows = '';
        $second = '';
        foreach ((new People($cfg))->active() as $pp) {
            $r = $codes['people'][$pp['id']] ?? null;
            $code = $r && !empty($r['rescue'][0]) ? '<span class="inst-code">' . $e($r['rescue'][0]) . '</span>' : 'on the earlier emergency sheet';
            $rows .= '<tr><th scope="row">' . $e((string)$pp['name']) . '</th><td><span class="inst-code">' . $e((string)$pp['username']) . '</span></td><td>' . $code . '</td></tr>';
            if ($r && !empty($r['rescue'][1])) $second .= '<li><b>' . $e((string)$pp['name']) . '</b>: <span class="inst-code">' . $e($r['rescue'][1]) . '</span></li>';
        }
        $to = ($st->d['leadEmail'] ?? '') !== '' ? [$st->d['leadEmail']] : array_column($this->leadAddresses(), 'email');
        $to = $to ?: [self::SITE_EMAIL];
        $gmail = array_values(array_filter($to, fn($a) => str_ends_with($a, '@gmail.com')))[0] ?? $to[0];
        $tools = '<div class="inst-tools no-print">' . $this->stepLine('sheet', $st) . '<h1>Print the emergency sheet</h1>'
            . '<p>Print it and keep it with the business papers. It has a rescue code for each person, so keep it somewhere safe.</p>'
            . '<button type="button" class="btn primary big" data-print>Print</button>'
            . ($second !== '' ? '<section class="inst-second"><h2>Second rescue codes: for the Passwords app</h2>'
                . '<p>These aren\'t printed. On each person\'s own iPhone, open the <b>Passwords</b> app, find the website editor\'s password, tap <b>Edit</b> and add the code under <b>Notes</b>.</p>'
                . '<ul class="inst-list">' . $second . '</ul></section>' : '')
            . '<form method="post" action="/edit/install/sheet" class="stack" data-once>' . $this->hidden() . '<input type="hidden" name="do" value="close">'
            . '<button class="btn inst-wide" type="submit">Done: it\'s printed' . ($second !== '' ? ' and the codes are saved' : '') . '</button></form>'
            . '<p class="small">After this, these codes can\'t be shown again. New ones can be made in the editor (More → People).</p></div>';
        $sheet = '<article class="inst-sheet" aria-label="Emergency sheet">'
            . '<header><p class="inst-kicker">Think Smart Public Adjusting</p><h2 class="inst-sheet-title">Website emergency sheet</h2><p>Made ' . $e($made) . '. Keep it somewhere safe: it has rescue codes.</p></header>'
            . '<dl class="inst-addr"><dt>Website</dt><dd>' . $e($site) . '</dd><dt>Website editor</dt><dd>' . $e($site) . '/edit</dd>'
            . '<dt>Leads are emailed to</dt><dd>' . $e(implode(', ', $to)) . ' (and kept in the editor for 90 days)</dd></dl>'
            . '<section><h3>Who can edit the website</h3><table class="inst-table"><thead><tr><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Rescue code</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="small">Passwords are never written down: each person\'s is saved in their iPhone\'s Passwords. Each rescue code works once.</p></section>'
            . '<section><h3>Forgot your password?</h3><ol><li>Open the website editor and tap <b>Forgot your password?</b></li>'
            . '<li>Type your username. A 6-digit code comes by email (check Spam). Type it in with a new password.</li>'
            . '<li>No email? Ask the other person to reset your password (More → People). Or tap <b>Use a rescue code</b> and type your code from this sheet.</li></ol></section>'
            . '<section class="inst-box"><h3>If someone leaves</h3><ol><li>Remove them in the website editor: More → People → Remove.</li><li>Change the GoDaddy password.</li>'
            . '<li>Change the Gmail password (' . $e($gmail) . ').</li></ol></section>'
            . '<section><h3>Who to call</h3><p><b>GoDaddy, ' . self::GODADDY_PHONE . '</b>, for the hosting, the domain name, the security certificate, or email from the website. Say: “My website is ' . $e((string)preg_replace('~^https?://(www\.)?~', '', $site)) . '.”</p></section>'
            . '</article>';
        return InstallResponse::page(self::layout('Emergency sheet · Website editor', '<main class="inst-sheet-page">' . $tools . $sheet . '</main>'));
    }

    // ---------------------------------------------------------------- the developer README

    /** <private>/README-developer.md: how this hosting is laid out, then edit/README.md. */
    public static function developerReadme(Paths $p, ?Config $cfg = null): string
    {
        $site = $cfg ? $cfg->siteUrl() : 'https://www.thinksmartpublicadjusting.com';
        $web = InstallChecks::absPath($p->webroot);
        $priv = InstallChecks::absPath($p->private);
        $home = dirname($web);
        $ini = 'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size');
        $editor = is_file($p->app . '/README.md') ? (string)file_get_contents($p->app . '/README.md') : "(edit/README.md was missing.)\n";
        $editor = (string)preg_replace('/^(#{2,5}) /m', '#$1 ', $editor);
        $editor = (string)preg_replace('/^# /m', '## ', $editor, 1);
        return "# Think Smart website: developer handover\n\n"
            . 'Written by the installer on ' . Clock::local(Clock::now())->format('M j, Y') . ". A copy is in every \"Download a copy\" zip.\n"
            . "The owners (John and Muneerah) change the website in the editor at $site/edit; nothing here is needed for that.\n\n"
            . "## How this hosting is laid out\n\n"
            . "- **Hosting:** GoDaddy cPanel. The website is the account's primary domain, so its folder is `public_html` in the home folder ($home).\n"
            . "- **Website folder (web root):** `$web`. Everything a visitor can open is here. The pages (`*.dc.html`, `404.html`, `index.html`, `sitemap.xml`) are\n"
            . "  built by the editor from the content: don't edit them by hand (the editor puts its own files back, see Health).\n"
            . "  Photos added in the editor are in `media/u/`, contracts at their permanent addresses in `docs/`.\n"
            . "- **Private folder:** `$priv` (0700, outside every website folder; the installer proved it doesn't open from the web).\n"
            . "  It holds `config.json` (the secret, mail mode; optional `bounce` mailbox login), `content.json`, `users.json` (password and rescue-code hashes),\n"
            . "  `sessions/`, `settings.json` (where leads go), `leads/` (90 days, photos 14), `history/`, `contracts/`, `builds/`, `pristine/`, `install.lock`.\n"
            . "- **The editor:** `$web/edit` (code; only `index.php` and `app/` are reachable, see `edit/.htaccess`). **The lead mailer:** `$web/send-lead.php`\n"
            . "  (saves each lead in the private folder first, then emails it; it runs without the editor's code).\n"
            . "- **Never changed by the editor:** `.htaccess`, `.user.ini`, `.well-known`, `cgi-bin` (cPanel writes to those).\n"
            . '- **PHP:** ' . PHP_VERSION . " when installed (cPanel → MultiPHP Manager; pick the newest). Uploads: $ini (cPanel → MultiPHP INI Editor; contracts need 16M).\n"
            . "- **Email:** PHP mail() from noreply@ the domain (SPF, DKIM and DMARC are in the domain's DNS). Sign-in codes, change alerts and leads.\n"
            . "- **Locks:** `install.lock` in the private folder and `edit/.installed` keep `/edit/install` closed (it answers 404).\n\n"
            . "## Reinstall and keep the content\n\n"
            . "For a hacked or broken website folder, or after restoring one:\n\n"
            . "1. Upload the launch kit zip (`think-smart-launch-kit-….zip`, in John's Google Drive) into `public_html` and Extract it there (overwrite).\n"
            . "2. The same day, open `$site/edit/install/reinstall` and type the setup code from the launch kit. (It only opens within a day of\n"
            . "   unzipping the kit, so the code alone can't reopen the setup; John gets an email when a reinstall starts and when it's done.)\n"
            . "3. It keeps `content.json`, `users.json`, `settings.json`, History and leads, makes a new secret (everyone signs in again; old email links stop\n"
            . "   working) and rebuilds the pages. Tick \"Make new rescue codes\" if the emergency sheet may be lost.\n"
            . "4. Do it right after extracting: until the reinstall refreshes `pristine/`, self-repair puts back the editor files it knew.\n\n"
            . "## Moving to another host\n\n"
            . "Copy this private folder to the new account's home folder as `thinksmart-private`, upload and extract the launch kit into `public_html`, put\n"
            . "`media/u/` back (from the old `public_html` or a \"Download a copy\" zip: `website/media/u`), then open `/edit/install/reinstall` (the copied\n"
            . "folder keeps its lock; without a lock, `/edit/install` offers to keep the content). From a \"Download a copy\" zip only: `private/content.json`\n"
            . "and `private/contracts/` go into a new `thinksmart-private`, `website/` into `public_html`, then `/edit/install`; logins are made again there.\n\n"
            . $editor;
    }
}
