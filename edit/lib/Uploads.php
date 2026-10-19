<?php
// Photos and contracts she adds. Photos arrive as JPEG (her phone shrinks them first); the
// server checks they really are pictures, removes everything but the picture (location, camera
// details, comments) in plain PHP, and saves them under a new name chosen here, so no visitor
// ever sees a stale cached copy. Contracts are kept privately, one file per version, and
// placed at their permanent web address only when the change goes live.
declare(strict_types=1);

namespace ThinkSmart;

final class Photos
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MAX_SIDE = 8000;
    public const MAX_PIXELS = 40000000;

    /** @return array{path: string, w: int, h: int, bytes: int} */
    public static function accept(Paths $p, string $tmp, string $slot): array
    {
        $data = (string)@file_get_contents($tmp);
        if ($data === '' || strlen($data) > self::MAX_BYTES) throw new UserError('That photo is too big. Pick it again (the editor shrinks it first).');
        if (strncmp($data, "\xFF\xD8\xFF", 3) === 0) { $data = self::stripJpeg($data); $ext = 'jpg'; }
        elseif (strncmp($data, "\x89PNG\r\n\x1A\n", 8) === 0) { $data = self::stripPng($data); $ext = 'png'; }
        elseif (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') { $data = self::stripWebp($data); $ext = 'webp'; }
        else throw new UserError('That file isn\'t a photo. Pick a photo from your library.');
        $info = @getimagesizefromstring($data);
        if (!$info || $info[0] < 1 || $info[1] < 1) throw new UserError('That photo couldn\'t be read. Try another one.');
        [$w, $h] = $info;
        if ($w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS) throw new UserError('That photo is too large. Pick it again so the editor can shrink it.');
        if (min($w, $h) < 100) throw new UserError('That photo is too small to use.');
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace('[]', '', $slot))), '-');
        $slug = substr($slug !== '' ? $slug : 'photo', 0, 40);
        $rel = "media/u/$slug-" . substr(hash('sha256', $data), 0, 12) . ".$ext";
        self::guardFolder($p->webroot . '/media/u');
        $dest = $p->webroot . '/' . $rel;
        if (!is_file($dest)) Files::writeAtomic($dest, $data);
        @chmod($dest, 0644);
        return ['path' => $rel, 'w' => $w, 'h' => $h, 'bytes' => strlen($data)];
    }

    /** The upload folder never runs anything (FilesMatch, not php_flag, which breaks shared hosts). */
    public static function guardFolder(string $dir): void
    {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @chmod($dir, 0755);
        $ht = "$dir/.htaccess";
        if (!is_file($ht)) {
            Files::writeAtomic($ht, "# Photos added in the website editor. Nothing here ever runs.\n"
                . "<FilesMatch \"(?i)\\.(php\\d?|phtml|phar|pl|py|cgi|sh|shtml|asp|aspx|jsp|htm|html|svg|js)$\">\n  Require all denied\n</FilesMatch>\n");
            @chmod($ht, 0644);
        }
    }

    /** Keep only what draws the picture (and its color profile); drop location, camera data, comments, extra images. */
    public static function stripJpeg(string $d): string
    {
        $n = strlen($d);
        $out = "\xFF\xD8";
        $i = 2;
        while ($i + 4 <= $n) {
            if ($d[$i] !== "\xFF") throw new UserError('That photo is damaged. Try another one.');
            $m = ord($d[$i + 1]);
            if ($m === 0xFF) { $i++; continue; }            // fill byte
            if ($m === 0xD9) break;                          // end of image
            if ($m >= 0xD0 && $m <= 0xD7) { $i += 2; continue; }
            $len = (ord($d[$i + 2]) << 8) | ord($d[$i + 3]);
            if ($len < 2 || $i + 2 + $len > $n) throw new UserError('That photo is damaged. Try another one.');
            $seg = substr($d, $i, 2 + $len);
            $keep = true;
            if ($m >= 0xE1 && $m <= 0xEF && $m !== 0xEE) $keep = $m === 0xE2 && substr($d, $i + 4, 12) === "ICC_PROFILE\0";
            if ($m === 0xFE) $keep = false;                  // comment
            if ($keep) $out .= $seg;
            $i += 2 + $len;
            if ($m === 0xDA) {                               // start of scan: copy the picture data up to the next marker
                $j = $i;
                while ($j + 1 < $n) {
                    if ($d[$j] === "\xFF") {
                        $b = ord($d[$j + 1]);
                        if ($b !== 0x00 && !($b >= 0xD0 && $b <= 0xD7)) break;
                    }
                    $j++;
                }
                $out .= substr($d, $i, $j - $i);
                $i = $j;
            }
        }
        return $out . "\xFF\xD9";
    }

    public static function stripPng(string $d): string
    {
        $keep = ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'pHYs'];
        $out = substr($d, 0, 8);
        $i = 8;
        $n = strlen($d);
        while ($i + 12 <= $n) {
            $len = unpack('N', substr($d, $i, 4))[1];
            $type = substr($d, $i + 4, 4);
            if ($i + 12 + $len > $n) throw new UserError('That picture is damaged. Try another one.');
            if ($type === 'acTL') throw new UserError('Moving pictures can\'t be used. Pick a photo.');
            if (in_array($type, $keep, true)) $out .= substr($d, $i, 12 + $len);
            $i += 12 + $len;
            if ($type === 'IEND') break;
        }
        return $out;
    }

    public static function stripWebp(string $d): string
    {
        $body = '';
        $i = 12;
        $n = strlen($d);
        $vp8x = null;
        while ($i + 8 <= $n) {
            $type = substr($d, $i, 4);
            $len = unpack('V', substr($d, $i + 4, 4))[1];
            $size = 8 + $len + ($len % 2);
            if ($i + 8 + $len > $n) throw new UserError('That picture is damaged. Try another one.');
            if ($type === 'ANIM' || $type === 'ANMF') throw new UserError('Moving pictures can\'t be used. Pick a photo.');
            $chunk = substr($d, $i, $size);
            if ($type === 'VP8X') { $chunk[8] = chr(ord($chunk[8]) & ~0x0C); $vp8x = true; }
            if ($type !== 'EXIF' && $type !== 'XMP ') $body .= $chunk;
            $i += $size;
        }
        return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
    }
}

final class Contracts
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    public static function dir(Paths $p): string
    {
        return $p->private . '/contracts';
    }

    public static function file(Paths $p, string $v): string
    {
        return self::dir($p) . '/' . preg_replace('/[^a-f0-9]/', '', $v) . '.pdf';
    }

    public static function index(Paths $p): array
    {
        return Json::read(self::dir($p) . '/index.json', []);
    }

    private static function add(Paths $p, string $data, array $meta): string
    {
        $v = substr(hash('sha256', $data), 0, 16);
        if (!is_file(self::file($p, $v))) Files::writeAtomic(self::file($p, $v), $data);
        $idx = self::index($p);
        if (!isset($idx[$v])) {
            $idx[$v] = $meta + ['v' => $v, 'bytes' => strlen($data)];
            Json::write(self::dir($p) . '/index.json', $idx);
        }
        return $v;
    }

    /** Keep the contract that's online now as the first version, so it can be viewed and brought back. */
    public static function keepLive(Paths $p, string $state, string $target): void
    {
        $f = $p->webroot . '/' . $target;
        if ($target === '' || !is_file($f)) return;
        $data = (string)file_get_contents($f);
        if (strncmp($data, '%PDF-', 5) !== 0) return;
        $v = substr(hash('sha256', $data), 0, 16);
        if (isset(self::index($p)[$v])) return;
        self::add($p, $data, ['state' => $state, 'at' => (int)filemtime($f), 'by' => null, 'name' => basename($target), 'first' => true]);
    }

    /** @return array{v: string, bytes: int} */
    public static function accept(Paths $p, string $tmp, string $state, string $person, string $name): array
    {
        $data = (string)@file_get_contents($tmp);
        if ($data === '') throw new UserError('The file didn\'t arrive. Try again.');
        if (strlen($data) > self::MAX_BYTES) throw new UserError('That PDF is too big (over 15 MB). Ask John for a smaller copy.');
        if (strncmp($data, '%PDF-', 5) !== 0) throw new UserError(self::notPdf($data, $name));
        $clean = substr((string)preg_replace('/[^A-Za-z0-9 ._()-]/', '', $name), 0, 80);
        $v = self::add($p, $data, ['state' => $state, 'at' => Clock::now(), 'by' => $person, 'name' => $clean]);
        return ['v' => $v, 'bytes' => strlen($data)];
    }

    /** A plain answer for a file that isn't a PDF. */
    public static function notPdf(string $data, string $name): string
    {
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, ['doc', 'docx'], true) || strncmp($data, "PK\x03\x04", 4) === 0 || strncmp($data, "\xD0\xCF\x11\xE0", 4) === 0) return 'This is a Word file. Ask John to send it as a PDF.';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'heic', 'webp'], true) || strncmp($data, "\xFF\xD8\xFF", 3) === 0) return 'This is a photo, not a PDF. Ask John to send the contract as a PDF.';
        if (in_array($ext, ['pages'], true)) return 'This is a Pages file. Ask John to send it as a PDF.';
        return 'This isn\'t a PDF. Ask John to send the contract as a PDF.';
    }

    /** Earlier contracts for a state, newest first. */
    public static function versions(Paths $p, string $state): array
    {
        $out = array_values(array_filter(self::index($p), fn($e) => ($e['state'] ?? '') === $state));
        usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
        return $out;
    }

    /** "docs/think-smart-agreement-kentucky.pdf" for a state that doesn't have one yet. */
    public static function permanentName(string $state): string
    {
        $name = Site::STATES[$state] ?? $state;
        return 'docs/think-smart-agreement-' . trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') . '.pdf';
    }
}
