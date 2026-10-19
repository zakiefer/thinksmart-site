<?php
// Where things live, and crash-proof file writes.
//
//   webroot   the live website folder (public_html on GoDaddy)
//   private   ~/thinksmart-private: content, builds, the swap journal (never web-reachable)
//   app       this edit/ folder: code, templates, the starting content
declare(strict_types=1);

namespace ThinkSmart;

final class Paths
{
    public string $webroot;
    public string $private;
    public string $app;

    public function __construct(string $webroot, string $private, string $app)
    {
        $this->webroot = rtrim($webroot, '/');
        $this->private = rtrim($private, '/');
        $this->app = rtrim($app, '/');
    }

    /** Defaults: the site is the folder above edit/, the private folder sits beside the site. */
    public static function detect(array $over = []): self
    {
        $app = dirname(__DIR__);
        $webroot = $over['webroot'] ?? (getenv('TS_WEBROOT') ?: dirname($app));
        $private = $over['private'] ?? (getenv('TS_PRIVATE_DIR') ?: dirname($webroot) . '/thinksmart-private');
        return new self($webroot, $private, $app);
    }

    public function templates(): string { return $this->app . '/templates'; }
    public function content(): string { return $this->private . '/content.json'; }
    public function builds(): string { return $this->private . '/builds'; }
    public function journal(): string { return $this->private . '/swap-journal.json'; }
}

final class Files
{
    /** Write via a temp file in the same folder, then rename (never a half-written file). */
    public static function writeAtomic(string $path, string $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new BuildError("Can't create $dir");
        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) { @unlink($tmp); throw new BuildError("Can't write $path"); }
        if (!@rename($tmp, $path)) { @unlink($tmp); throw new BuildError("Can't replace $path"); }
    }

    public static function sha(string $path): ?string
    {
        return is_file($path) ? hash_file('sha256', $path) : null;
    }

    public static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            is_dir($p) && !is_link($p) ? self::rmTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

/**
 * The content file. Saved as temp-then-rename with the previous good copy kept beside it;
 * if the file is ever unreadable, the last good copy loads instead.
 */
final class ContentStore
{
    private Paths $paths;

    public function __construct(Paths $paths)
    {
        $this->paths = $paths;
    }

    /** @return array{0: array, 1: string} content and where it came from */
    public function load(): array
    {
        $main = $this->paths->content();
        foreach ([$main, $main . '.good', $this->paths->app . '/seed/content.json'] as $f) {
            if (!is_file($f)) continue;
            $c = json_decode((string)@file_get_contents($f), true);
            if (is_array($c) && isset($c['site'])) {
                if ($f !== $main) $this->save($c); // put a readable copy back in place
                return [$c, $f];
            }
        }
        throw new BuildError('No readable content');
    }

    public function save(array $content): void
    {
        $content = Content::clean($content);
        $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $main = $this->paths->content();
        if (is_file($main) && is_array(json_decode((string)file_get_contents($main), true))) {
            Files::writeAtomic($main . '.good', (string)file_get_contents($main));
        }
        Files::writeAtomic($main, $json);
    }
}

/** Input rules applied to every stored string (see Input::clean), and checks on the settings. */
final class Content
{
    /** Problems that would put a wrong phone, email or link on the site. */
    public static function problems(array $c): array
    {
        $p = [];
        $s = $c['site'] ?? [];
        foreach (['phone' => true, 'phone2' => false] as $k => $required) {
            $v = trim((string)($s[$k] ?? ''));
            if ($v === '' && !$required) continue;
            if (strlen((string)preg_replace('/\D/', '', $v)) !== 10) $p[] = "The $k setting needs a 10-digit number";
        }
        if (!preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/', (string)($s['email'] ?? ''))) $p[] = 'The email setting is not an email address';
        foreach ($c['states'] ?? [] as $i => $st) {
            if (!isset(Site::STATES[$st['code'] ?? ''])) $p[] = "State #" . ($i + 1) . " is not a US state";
            if (!empty($st['esign']) && !preg_match('~^https://~', (string)$st['esign'])) $p[] = "State #" . ($i + 1) . ": the e-sign link must start with https://";
            if (!empty($st['pdf']) && !preg_match('~^docs/[a-z0-9._-]+\.pdf$~', (string)$st['pdf'])) $p[] = "State #" . ($i + 1) . ": the contract must be a PDF in docs/";
        }
        foreach ($s['social'] ?? [] as $i => $so) {
            if (!preg_match('~^https://~', (string)($so['url'] ?? ''))) $p[] = 'Social link #' . ($i + 1) . ' must start with https://';
        }
        return $p;
    }

    public static function clean($v)
    {
        if (is_string($v)) return Input::clean($v);
        if (!is_array($v)) return $v;
        $out = [];
        foreach ($v as $k => $x) $out[$k] = self::clean($x);
        return $out;
    }
}
