<?php
// "Download a copy" (More → Download a copy, signed in only): one zip with what's needed to
// put the website back somewhere else: the content, every photo added in the editor, the
// contracts (live ones and every earlier version), the settings without lead addresses, and
// the developer README. Never logins, passwords, sessions, codes, email links, leads,
// config.json (the secret, and any mailbox password) or anything else from the private folder.
//
//   README-developer.md
//   private/content.json             goes into ~/thinksmart-private
//   private/settings.json            (lead addresses taken out)
//   private/contracts/index.json, <version>.pdf
//   website/docs/*.pdf               goes into public_html
//   website/media/u/*                photos added in the editor
declare(strict_types=1);

namespace ThinkSmart;

final class Backup
{
    /** Never in a copy, whatever else changes in the private folder. */
    public const NEVER = ['users.json', 'config.json', 'sessions', 'codes.json', 'tokens.json', 'throttle.json', 'leads', 'install',
        'outbox', 'drafts', 'history', 'pristine', 'builds', 'editor-errors.log', 'php-errors.log'];

    public static function fileName(): string
    {
        return 'think-smart-website-copy-' . Clock::local(Clock::now())->format('Y-m-d') . '.zip';
    }

    /** Streams the zip to her phone or computer. */
    public static function download(Config $cfg): void
    {
        @set_time_limit(300);
        $parts = self::parts($cfg); // everything is found before anything is sent
        @ini_set('zlib.output_compression', 'Off');
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        while (ob_get_level() > 0) @ob_end_clean();
        Http::headers('api');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . self::fileName() . '"');
        $out = fopen('php://output', 'wb');
        if ($out === false) throw new \RuntimeException('Can\'t send the copy');
        self::write($parts, $out);
        fclose($out);
    }

    /**
     * What goes in the copy: zip name => ['file' => path] or ['data' => string].
     * @return array<string, array{file?: string, data?: string}>
     */
    public static function parts(Config $cfg): array
    {
        $p = $cfg->paths;
        $out = ['README-developer.md' => ['data' => is_file($p->private . '/README-developer.md')
            ? (string)file_get_contents($p->private . '/README-developer.md') : Installer::developerReadme($p, $cfg)]];
        foreach ([$p->content(), $p->content() . '.good'] as $f) {
            $c = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
            if (is_array($c) && isset($c['site'])) { $out['private/content.json'] = ['file' => $f]; break; }
        }
        $settings = Json::read($p->private . '/settings.json');
        if (is_array($settings)) {
            $out['private/settings.json'] = ['data' => json_encode(self::withoutAddresses($settings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"];
        }
        foreach (self::files($p->private . '/contracts', '/^([a-f0-9]{16}\.pdf|index\.json)$/') as $name => $f) $out["private/contracts/$name"] = ['file' => $f];
        foreach (self::files($p->webroot . '/docs', '/^[a-z0-9._-]+\.pdf$/') as $name => $f) $out["website/docs/$name"] = ['file' => $f];
        foreach (self::files($p->webroot . '/media/u', '/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/') as $name => $f) $out["website/media/u/$name"] = ['file' => $f];
        return $out;
    }

    /** Settings with every lead address (and any other email address) taken out. */
    public static function withoutAddresses(array $s): array
    {
        unset($s['leadTo']);
        $walk = function ($v) use (&$walk) {
            if (is_array($v)) {
                $o = [];
                foreach ($v as $k => $x) {
                    if (is_string($x) && preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+/', $x)) continue;
                    $o[$k] = $walk($x);
                }
                return $o;
            }
            return $v;
        };
        return $walk($s);
    }

    /** Plain files in one folder (no links, no subfolders) whose names match. */
    private static function files(string $dir, string $pattern): array
    {
        $out = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $f) {
            $full = "$dir/$f";
            if (preg_match($pattern, $f) && is_file($full) && !is_link($full) && is_readable($full)) $out[$f] = $full;
        }
        return $out;
    }

    /** @param resource $out @return list<string> the names written */
    public static function write(array $parts, $out): array
    {
        $zip = new Zip($out);
        $names = [];
        foreach ($parts as $name => $part) {
            if (isset($part['file'])) $zip->addFile($name, $part['file']);
            else $zip->addData($name, (string)$part['data'], Clock::now());
            $names[] = $name;
        }
        $zip->finish();
        return $names;
    }
}
