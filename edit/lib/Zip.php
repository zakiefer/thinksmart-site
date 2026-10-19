<?php
// A small zip writer in plain PHP (no ZipArchive needed), for "Download a copy" and the
// launch kit. An entry is deflated with gzdeflate when the host has zlib and it makes the
// entry smaller (photos and PDFs usually stay as they are); without zlib everything is
// stored. Big files are streamed and stored, so a 15 MB contract never sits in memory twice.
// Reading is only for checks (the tests, make-zip's self-check): list the entries, read one.
declare(strict_types=1);

namespace ThinkSmart;

final class Zip
{
    /** Files bigger than this are streamed (stored, never read whole). */
    public const BIG = 4 * 1024 * 1024;

    /** @var resource */
    private $out;
    private bool $deflate;
    private int $offset = 0;
    /** @var list<string> */
    private array $central = [];
    private array $names = [];
    private bool $finished = false;

    /** @param resource $out a writable stream: a file, or php://output for a download */
    public function __construct($out, bool $deflate = true)
    {
        if (!is_resource($out)) throw new \InvalidArgumentException('The zip needs a stream to write to');
        $this->out = $out;
        $this->deflate = $deflate && function_exists('gzdeflate');
    }

    /** Forward slashes, nothing leading, no empty, "." or ".." parts, no control characters. */
    public static function cleanName(string $name): string
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        $dir = str_ends_with($name, '/');
        $parts = explode('/', rtrim($name, '/'));
        foreach ($parts as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') throw new \InvalidArgumentException("Not a usable name in a zip: $name");
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) throw new \InvalidArgumentException('A name in a zip has a control character');
        return implode('/', $parts) . ($dir ? '/' : '');
    }

    public function addDir(string $name, ?int $mtime = null, int $mode = 0755): void
    {
        $name = rtrim(self::cleanName($name), '/') . '/';
        $this->header($name, 0, 0, 0, 0, $mtime ?? time(), 0040000 | ($mode & 0777), true);
    }

    public function addData(string $name, string $data, ?int $mtime = null, int $mode = 0644): void
    {
        $name = self::cleanName($name);
        $crc = crc32($data);
        $method = 0;
        $body = $data;
        if ($this->deflate && strlen($data) > 100) {
            $z = gzdeflate($data, 6);
            if (is_string($z) && strlen($z) < strlen($data) * 0.95) { $method = 8; $body = $z; }
        }
        $this->header($name, $method, $crc, strlen($body), strlen($data), $mtime ?? time(), 0100000 | ($mode & 0777));
        $this->write($body);
    }

    public function addFile(string $name, string $path, ?int $mode = null): void
    {
        if (!is_file($path) || !is_readable($path)) throw new \RuntimeException("Can't read $path for the zip");
        $size = (int)filesize($path);
        $mtime = (int)filemtime($path);
        $mode = $mode ?? 0644;
        if ($size <= self::BIG) {
            $data = file_get_contents($path);
            if (!is_string($data) || strlen($data) !== $size) throw new \RuntimeException("Can't read $path for the zip");
            $this->addData($name, $data, $mtime, $mode);
            return;
        }
        $name = self::cleanName($name);
        $crc = (int)hexdec((string)hash_file('crc32b', $path));
        $this->header($name, 0, $crc, $size, $size, $mtime, 0100000 | ($mode & 0777));
        $h = fopen($path, 'rb');
        if ($h === false) throw new \RuntimeException("Can't read $path for the zip");
        $sent = 0;
        while (!feof($h)) {
            $chunk = fread($h, 1048576);
            if ($chunk === false) break;
            if ($chunk === '') continue;
            $this->write($chunk);
            $sent += strlen($chunk);
        }
        fclose($h);
        if ($sent !== $size) throw new \RuntimeException("$path changed while it was being zipped");
    }

    /** Write the table of contents. @return int the zip's size in bytes */
    public function finish(): int
    {
        if ($this->finished) return $this->offset;
        $start = $this->offset;
        foreach ($this->central as $c) $this->write($c);
        $n = count($this->central);
        $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, $this->offset - $start, $start, 0));
        $this->finished = true;
        return $this->offset;
    }

    public function count(): int
    {
        return count($this->central);
    }

    private function header(string $name, int $method, int $crc, int $csize, int $usize, int $mtime, int $attr, bool $dir = false): void
    {
        if ($this->finished) throw new \LogicException('The zip is already finished');
        if (isset($this->names[$name])) throw new \InvalidArgumentException("$name is in the zip twice");
        if (count($this->central) >= 65535 || $this->offset + $csize > 0xFFFFFFFF - 65536) throw new \RuntimeException('Too much for one zip');
        [$time, $date] = self::dosTime($mtime);
        $flags = 0x0800;                       // names are UTF-8
        $need = $method === 8 || $dir ? 20 : 10;
        $this->central[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, (3 << 8) | 20, $need, $flags, $method, $time, $date,
            $crc, $csize, $usize, strlen($name), 0, 0, 0, 0, (($attr & 0xFFFF) << 16) | ($dir ? 0x10 : 0), $this->offset) . $name;
        $this->names[$name] = true;
        $this->write(pack('VvvvvvVVVvv', 0x04034b50, $need, $flags, $method, $time, $date, $crc, $csize, $usize, strlen($name), 0) . $name);
    }

    private function write(string $s): void
    {
        $len = strlen($s);
        $done = 0;
        while ($done < $len) {
            $n = @fwrite($this->out, $done === 0 ? $s : substr($s, $done));
            if ($n === false || $n === 0) throw new \RuntimeException('Writing the zip stopped');
            $done += $n;
        }
        $this->offset += $len;
    }

    /**
     * MS-DOS time and date (zips can't go before 1980). A zip's times have no time zone: each
     * computer reads them as its own clock. They're written as the earliest clock on Earth
     * (UTC-12), so files unpacked on any server never look newer than they are.
     * @return array{0:int,1:int}
     */
    private static function dosTime(int $t): array
    {
        $d = (new \DateTimeImmutable('@' . max($t, 315576000)))->setTimezone(new \DateTimeZone('Etc/GMT+12'));
        [$y, $mo, $day, $h, $mi, $s] = array_map('intval', explode(' ', $d->format('Y n j G i s')));
        if ($y < 1980) [$y, $mo, $day, $h, $mi, $s] = [1980, 1, 1, 0, 0, 0];
        return [($h << 11) | ($mi << 5) | intdiv($s, 2), (($y - 1980) << 9) | ($mo << 5) | $day];
    }

    // ---------------------------------------------------------------- reading (checks only)

    /**
     * Every entry of a zip file.
     * @return array<string, array{method:int, crc:int, csize:int, size:int, offset:int, mode:int}>
     */
    public static function entries(string $path): array
    {
        $h = @fopen($path, 'rb');
        if ($h === false) throw new \RuntimeException("Can't open $path");
        try {
            $size = (int)fstat($h)['size'];
            $tail = min($size, 65557);
            fseek($h, $size - $tail);
            $buf = (string)fread($h, $tail);
            $at = strrpos($buf, "PK\x05\x06");
            if ($at === false || strlen($buf) - $at < 22) throw new \RuntimeException("$path is not a zip");
            $e = unpack('Vsig/vdisk/vcdisk/vhere/vtotal/Vcdsize/Vcdoffset/vclen', substr($buf, $at, 22));
            fseek($h, (int)$e['cdoffset']);
            $cd = (string)fread($h, (int)$e['cdsize']);
            $out = [];
            $i = 0;
            for ($k = 0; $k < (int)$e['total']; $k++) {
                if (substr($cd, $i, 4) !== "PK\x01\x02") throw new \RuntimeException("$path has a damaged table of contents");
                $c = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voffset', substr($cd, $i, 46));
                $name = substr($cd, $i + 46, (int)$c['nlen']);
                $out[$name] = ['method' => (int)$c['method'], 'crc' => (int)$c['crc'], 'csize' => (int)$c['csize'], 'size' => (int)$c['size'],
                    'offset' => (int)$c['offset'], 'mode' => ((int)$c['ext'] >> 16) & 0xFFFF];
                $i += 46 + (int)$c['nlen'] + (int)$c['xlen'] + (int)$c['clen'];
            }
            return $out;
        } finally {
            fclose($h);
        }
    }

    /** One entry's contents, checked against its CRC (null when it isn't there or is damaged). */
    public static function read(string $path, string $name, ?array $entries = null): ?string
    {
        $entries = $entries ?? self::entries($path);
        $e = $entries[$name] ?? null;
        if ($e === null) return null;
        $h = @fopen($path, 'rb');
        if ($h === false) return null;
        fseek($h, $e['offset']);
        $lh = unpack('Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen', (string)fread($h, 30));
        if ((int)$lh['sig'] !== 0x04034b50) { fclose($h); return null; }
        fseek($h, $e['offset'] + 30 + (int)$lh['nlen'] + (int)$lh['xlen']);
        $raw = $e['csize'] > 0 ? (string)fread($h, $e['csize']) : '';
        fclose($h);
        if ($e['method'] === 8) {
            if (!function_exists('gzinflate')) return null;
            $raw = @gzinflate($raw);
            if (!is_string($raw)) return null;
        } elseif ($e['method'] !== 0) {
            return null;
        }
        return strlen($raw) === $e['size'] && crc32($raw) === $e['crc'] ? $raw : null;
    }
}
