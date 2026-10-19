<?php
// Small shared pieces for the editor: its settings file, the clock (Indiana/Central time for
// everything she reads), locking, JSON files and the error log.
declare(strict_types=1);

namespace ThinkSmart;

final class Config
{
    private array $c;
    public Paths $paths;

    public function __construct(Paths $paths, array $c)
    {
        $this->paths = $paths;
        $this->c = $c;
    }

    /** <private>/config.json, written by the installer. */
    public static function load(Paths $paths): self
    {
        $f = $paths->private . '/config.json';
        $c = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return new self($paths, is_array($c) ? $c : []);
    }

    public function ready(): bool
    {
        return isset($this->c['secret']) && is_string($this->c['secret']) && strlen($this->c['secret']) >= 32;
    }

    public function get(string $key, $default = null)
    {
        return $this->c[$key] ?? $default;
    }

    /** Local development only: allows plain http on localhost and keeps mail in an outbox. */
    public function dev(): bool
    {
        return !empty($this->c['dev']);
    }

    public function secret(): string
    {
        return (string)($this->c['secret'] ?? '');
    }

    /** The public address of the website, for links in emails. */
    public function siteUrl(): string
    {
        return rtrim((string)($this->c['siteUrl'] ?? 'https://www.thinksmartpublicadjusting.com'), '/');
    }

    public function mailMode(): string
    {
        return (string)($this->c['mail'] ?? ($this->dev() ? 'outbox' : 'mail'));
    }

    public function hmac(string $what): string
    {
        return hash_hmac('sha256', $what, $this->secret());
    }
}

final class Clock
{
    public const TZ = 'America/Chicago';
    private static ?int $fixed = null;

    public static function now(): int
    {
        return self::$fixed ?? time();
    }

    /** Tests move the clock. */
    public static function set(?int $t): void
    {
        self::$fixed = $t;
    }

    public static function local(int $t): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone(self::TZ));
    }

    /** "2:14 pm" */
    public static function time(int $t): string
    {
        $d = self::local($t);
        return $d->format('g:i') . ' ' . $d->format('a');
    }

    /** "Today 2:14 pm", "Yesterday 9:12 am", "Tue 2:14 pm" (this week), "Oct 3 2:14 pm", "Oct 3, 2025" */
    public static function when(int $t): string
    {
        $d = self::local($t);
        $now = self::local(self::now());
        $days = (int)$now->setTime(0, 0)->diff($d->setTime(0, 0))->format('%r%a');
        if ($days === 0) return 'Today ' . self::time($t);
        if ($days === -1) return 'Yesterday ' . self::time($t);
        if ($days > -7 && $days < 0) return $d->format('D') . ' ' . self::time($t);
        if ($d->format('Y') === $now->format('Y')) return $d->format('M j') . ' ' . self::time($t);
        return $d->format('M j, Y');
    }

    public static function iso(int $t): string
    {
        return gmdate('c', $t);
    }
}

/** One lock file per kind of change, so two phones saving at once take turns. */
final class Lock
{
    /** @template T @param callable():T $fn @return T */
    public static function with(Paths $p, string $name, callable $fn)
    {
        $dir = $p->private . '/locks';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $h = @fopen("$dir/$name.lock", 'c');
        if ($h === false) throw new BuildError("Can't open the $name lock");
        try {
            if (!flock($h, LOCK_EX)) throw new BuildError("Can't take the $name lock");
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}

final class Json
{
    public static function read(string $path, $default = null)
    {
        if (!is_file($path)) return $default;
        $raw = (string)@file_get_contents($path);
        if (str_ends_with($path, '.gz')) {
            if (!function_exists('gzdecode')) return $default;
            $raw = (string)@gzdecode($raw);
        }
        $v = json_decode($raw, true);
        return $v === null && trim($raw) !== 'null' ? $default : $v;
    }

    public static function write(string $path, $data, bool $pretty = true): void
    {
        $json = json_encode($data, ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (str_ends_with($path, '.gz')) $json = (string)gzencode($json, 6);
        Files::writeAtomic($path, $json);
    }

    /** Snapshot file name: gzipped when the host has zlib, plain otherwise. */
    public static function packed(string $base): string
    {
        return function_exists('gzencode') ? "$base.json.gz" : "$base.json";
    }
}

/** Problems go to <private>/editor-errors.log, never to her screen and never into the web folder. */
final class Log
{
    private static string $file = '';

    public static function to(string $file): void
    {
        self::$file = $file;
    }

    public static function error(string $msg): void
    {
        if (self::$file === '') return;
        @file_put_contents(self::$file, gmdate('c') . ' ' . str_replace(["\r", "\n"], ' ', $msg) . "\n", FILE_APPEND | LOCK_EX);
        // keep the log small: past 1 MB, keep the newest half
        if (@filesize(self::$file) > 1048576) {
            $t = (string)@file_get_contents(self::$file);
            @file_put_contents(self::$file, substr($t, (int)(strlen($t) / 2)), LOCK_EX);
        }
    }
}
