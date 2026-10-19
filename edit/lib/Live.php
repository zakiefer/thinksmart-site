<?php
// The live content, its history, and each person's draft.
//
//   <private>/content.json            what the website shows now (list items carry "_id")
//   <private>/history/index.json      one entry per change that went live, oldest first
//   <private>/history/r<rev>.json.gz  the whole content after that change, plus its operations
//   <private>/drafts/<person>.json    changes not live yet: {base, ops, saved, v}
declare(strict_types=1);

namespace ThinkSmart;

final class Schema
{
    private static array $cache = [];

    public static function load(Paths $p): array
    {
        $f = $p->app . '/schema.json';
        if (!isset(self::$cache[$f])) {
            $s = json_decode((string)file_get_contents($f), true);
            if (!is_array($s)) throw new BuildError('schema.json is unreadable');
            self::$cache[$f] = $s;
        }
        return self::$cache[$f];
    }

    /** Schema path of a dotted content path: "faq.items.3.q" -> "faq.items[].q". */
    public static function pathOf(array $schema, string $dotted): string
    {
        $out = '';
        foreach (explode('.', $dotted) as $seg) {
            if (preg_match('/^\d+$/', $seg) && $out !== '' && in_array($schema['fields'][$out]['type'] ?? '', ['list', 'checklist'], true)) { $out .= '[]'; continue; }
            $out .= ($out === '' ? '' : '.') . $seg;
        }
        return $out;
    }
}

final class LiveContent
{
    private Config $cfg;
    private array $lists;

    public function __construct(Config $cfg, array $schema)
    {
        $this->cfg = $cfg;
        $this->lists = Changes::listPaths($schema);
    }

    public function lists(): array
    {
        return $this->lists;
    }

    /** The live content, with an "_id" on every list item (added once, then kept). */
    public function load(): array
    {
        [$c] = (new ContentStore($this->cfg->paths))->load();
        $withIds = Changes::ensureIds($c, $this->lists);
        if ($withIds !== $c) (new ContentStore($this->cfg->paths))->save($withIds);
        return $withIds;
    }
}

final class History
{
    public const KEEP_DAYS = 90;

    private Config $cfg;
    private string $dir;
    private ?array $index = null;

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
        $this->dir = $cfg->paths->private . '/history';
    }

    public function entries(): array
    {
        if ($this->index === null) $this->index = Json::read($this->dir . '/index.json', []);
        return $this->index;
    }

    public function head(): ?array
    {
        $e = $this->entries();
        return $e ? $e[count($e) - 1] : null;
    }

    public function rev(): int
    {
        return (int)($this->head()['rev'] ?? 0);
    }

    public function get(int $rev): ?array
    {
        foreach ($this->entries() as $e) if ($e['rev'] === $rev) return $e;
        return null;
    }

    private function snapFile(int $rev): ?string
    {
        foreach (["r$rev.json.gz", "r$rev.json"] as $n) if (is_file("$this->dir/$n")) return "$this->dir/$n";
        return null;
    }

    /** @return array{content: array, ops: array}|null */
    public function snapshot(int $rev): ?array
    {
        $f = $this->snapFile($rev);
        $s = $f ? Json::read($f) : null;
        return is_array($s) && isset($s['content']) ? $s : null;
    }

    public function byPublishId(string $id): ?array
    {
        foreach (array_reverse($this->entries()) as $e) if (($e['publishId'] ?? '') === $id) return $e;
        return null;
    }

    /** Record a change that went live. The first entry is the launch-day version, kept forever. */
    public function add(array $entry, array $content, array $ops): array
    {
        $entry['rev'] = $this->rev() + 1;
        $media = [];
        self::collectMedia($content, $media);
        $entry['media'] = array_keys($media);
        Json::write(Json::packed($this->dir . '/r' . $entry['rev']), ['content' => $content, 'ops' => $ops], false);
        $this->index = $this->entries();
        $this->index[] = $entry;
        Json::write($this->dir . '/index.json', $this->index);
        return $entry;
    }

    public function update(int $rev, array $changes): void
    {
        $this->index = $this->entries();
        foreach ($this->index as $i => $e) if ($e['rev'] === $rev) $this->index[$i] = $changes + $e;
        Json::write($this->dir . '/index.json', $this->index);
    }

    /** The newest change after $rev that touched this spot (or the list it sits in). */
    public function touching(string $key, int $afterRev): ?array
    {
        foreach (array_reverse($this->entries()) as $e) {
            if ($e['rev'] <= $afterRev) break;
            foreach ($e['keys'] ?? [] as $k) {
                if ($k === $key || str_starts_with($key, $k . '.') || str_starts_with($key, $k . '[') || str_starts_with($k, $key . '.') || str_starts_with($k, $key . '[')) return $e;
            }
        }
        return null;
    }

    /**
     * Every change from the last 90 days; before that one a week, then one a month, plus the
     * launch-day version forever.
     */
    public function prune(): void
    {
        $now = Clock::now();
        $keep = [];
        $seen = [];
        foreach (array_reverse($this->entries()) as $e) {
            $age = $now - $e['at'];
            if ($e['rev'] === 1 || $age < self::KEEP_DAYS * 86400 || $e === $this->head()) { $keep[] = $e; continue; }
            $d = Clock::local($e['at']);
            $bucket = $age < 365 * 86400 ? 'w' . $d->format('o-W') : 'm' . $d->format('Y-m');
            if (isset($seen[$bucket])) { if ($f = $this->snapFile($e['rev'])) @unlink($f); continue; }
            $seen[$bucket] = true;
            $keep[] = $e;
        }
        $this->index = array_reverse($keep);
        Json::write($this->dir . '/index.json', $this->index);
    }

    /** Media files (photos) any kept version uses, so old versions can still be brought back. */
    public function mediaInUse(): array
    {
        $used = [];
        foreach ($this->entries() as $e) {
            if (isset($e['media'])) { foreach ($e['media'] as $m) $used[$m] = true; continue; }
            $s = $this->snapshot($e['rev']);
            if ($s) self::collectMedia($s['content'], $used);
        }
        return $used;
    }

    public static function collectMedia($v, array &$used): void
    {
        if (is_string($v)) { if (preg_match('~^media/u/[A-Za-z0-9._-]+$~', $v)) $used[$v] = true; return; }
        if (is_array($v)) foreach ($v as $x) self::collectMedia($x, $used);
    }
}

final class Drafts
{
    private Config $cfg;
    private string $dir;

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
        $this->dir = $cfg->paths->private . '/drafts';
    }

    private function file(string $person): string
    {
        return $this->dir . '/' . preg_replace('/[^A-Za-z0-9]/', '', $person) . '.json';
    }

    /** @return array{base:int, ops:array, saved:int, v:int} */
    public function get(string $person): array
    {
        $d = Json::read($this->file($person), null);
        $empty = ['base' => 0, 'ops' => [], 'saved' => 0, 'v' => 0];
        return is_array($d) ? $d + $empty : $empty;
    }

    /**
     * Save the draft. "v" counts saves, so the editor open on a second phone (or tab) can't
     * quietly overwrite newer changes: with $expect, a draft saved since is left alone and
     * returned with "stale".
     */
    public function put(string $person, int $base, array $ops, ?int $expect = null): array
    {
        return Lock::with($this->cfg->paths, 'drafts', function () use ($person, $base, $ops, $expect) {
            $cur = $this->get($person);
            if ($expect !== null && $cur['ops'] && (int)($cur['v'] ?? 0) !== $expect) return ['stale' => true] + $cur;
            $d = ['base' => $base, 'ops' => array_values($ops), 'saved' => Clock::now(), 'v' => (int)($cur['v'] ?? 0) + 1];
            if (!$ops) { @unlink($this->file($person)); return $d; }
            Json::write($this->file($person), $d, false);
            return $d;
        });
    }

    /** After a change went live: drop the operations that went, keep the rest on the new base. */
    public function settle(string $person, array $wentKeys, int $newBase): void
    {
        $d = $this->get($person);
        $left = array_values(array_filter($d['ops'], fn($op) => !in_array(Changes::key($op['path']), $wentKeys, true)));
        $this->put($person, $newBase, $left);
    }

    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $f) $out[basename($f, '.json')] = Json::read($f, []);
        return $out;
    }
}
