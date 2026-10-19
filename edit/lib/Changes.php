<?php
// Changes to the content, as small operations that survive two people editing at once.
//
// Every list item carries a stable "_id", so an operation names "the FAQ item k3f9", not "the
// 4th FAQ item". A draft is a list of operations made against the live content:
//   {"op":"set",  "path":["faq","items",{"id":"k3f9"},"q"], "value":…, "old":…}
//   {"op":"list", "path":["faq","items"], "order":["k3f9",…], "add":{"n7x2":{…}}, "old":["k3f9",…]}
// Applying them to newer live content keeps everyone else's edits; a "set" whose spot was
// changed by someone else in the meantime is a conflict ("Keep his / Use mine").
declare(strict_types=1);

namespace ThinkSmart;

final class Changes
{
    /**
     * Stored lists (from the schema) as dotted paths with [] for nested lists, plus the arrays
     * whose entries are separate spots ("home.top.title" holds headline lines .0 and .1) as
     * path => 'lines'.
     */
    public static function listPaths(array $schema): array
    {
        $out = [];
        foreach ($schema['fields'] ?? [] as $path => $f) {
            if (in_array($f['type'] ?? '', ['list', 'checklist'], true)) $out[$path] = true;
            if (preg_match('/^(.+)\.\d+$/', $path, $m) && !isset($out[$m[1]])) $out[$m[1]] = 'lines';
        }
        return $out;
    }

    /** Give every list item an "_id" (kept forever; new items get new ones). */
    public static function ensureIds(array $content, array $lists): array
    {
        $walk = function ($node, string $path) use (&$walk, $lists) {
            if (!is_array($node)) return $node;
            if (($lists[$path] ?? null) === true && array_is_list($node)) {
                $seen = [];
                foreach ($node as $i => $item) {
                    if (!is_array($item) || array_is_list($item)) continue;
                    if (empty($item['_id']) || isset($seen[$item['_id']])) $item['_id'] = self::newId();
                    $seen[$item['_id']] = true;
                    foreach ($item as $k => $v) if ($k !== '_id') $item[$k] = $walk($v, "{$path}[].$k");
                    $node[$i] = $item;
                }
                return $node;
            }
            if (array_is_list($node)) return $node;
            foreach ($node as $k => $v) $node[$k] = $walk($v, $path === '' ? (string)$k : "$path.$k");
            return $node;
        };
        return $walk($content, '');
    }

    public static function newId(): string
    {
        return substr(base_convert(bin2hex(random_bytes(5)), 16, 36), 0, 6);
    }

    /** Schema path of a change path: ["faq","items",{"id":…},"q"] -> "faq.items[].q". */
    public static function schemaPath(array $path): string
    {
        $s = '';
        foreach ($path as $seg) {
            if (is_array($seg)) $s .= '[]';
            else $s .= ($s === '' ? '' : '.') . $seg;
        }
        return $s;
    }

    /** Stable text key of a change path: "faq.items[k3f9].q". */
    public static function key(array $path): string
    {
        $s = '';
        foreach ($path as $seg) {
            if (is_array($seg)) $s .= '[' . $seg['id'] . ']';
            else $s .= ($s === '' ? '' : '.') . $seg;
        }
        return $s;
    }

    /** Operations that turn $from into $to. */
    public static function diff($from, $to, array $lists, array $path = []): array
    {
        $sp = self::schemaPath($path);
        if (($lists[$sp] ?? null) === 'lines' && is_array($from) && is_array($to) && array_is_list($from) && array_is_list($to) && count($from) === count($to)) {
            $ops = [];
            foreach ($to as $i => $v) foreach (self::diff($from[$i], $v, $lists, array_merge($path, [(string)$i])) as $op) $ops[] = $op;
            return $ops;
        }
        if (($lists[$sp] ?? null) === true && is_array($from) && is_array($to)) {
            $ops = [];
            $fromIds = array_map(fn($it) => $it['_id'] ?? null, $from);
            $toIds = array_map(fn($it) => $it['_id'] ?? null, $to);
            if ($fromIds !== $toIds) {
                $add = [];
                foreach ($to as $it) if (!in_array($it['_id'], $fromIds, true)) $add[$it['_id']] = $it;
                $ops[] = ['op' => 'list', 'path' => $path, 'order' => $toIds, 'add' => $add, 'old' => $fromIds];
            }
            $byId = [];
            foreach ($from as $it) $byId[$it['_id']] = $it;
            foreach ($to as $it) {
                if (!isset($byId[$it['_id']])) continue;
                foreach (self::diff($byId[$it['_id']], $it, $lists, array_merge($path, [['id' => $it['_id']]])) as $op) $ops[] = $op;
            }
            return $ops;
        }
        if (self::isObject($from) && self::isObject($to)) {
            $ops = [];
            foreach (array_unique(array_merge(array_keys($from), array_keys($to))) as $k) {
                if ($k === '_id') continue;
                $a = $from[$k] ?? null;
                $b = $to[$k] ?? null;
                foreach (self::diff($a, $b, $lists, array_merge($path, [(string)$k])) as $op) $ops[] = $op;
            }
            return $ops;
        }
        return $from === $to ? [] : [['op' => 'set', 'path' => $path, 'value' => $to, 'old' => $from]];
    }

    private static function isObject($v): bool
    {
        return is_array($v) && ($v === [] ? false : !array_is_list($v));
    }

    /**
     * Apply operations to (newer) content. $resolve maps a change key to "mine" or "theirs" for
     * spots that someone else changed meanwhile. Returns [content, conflicts]; a conflicting
     * "set" without a resolution is left out and reported.
     */
    public static function apply(array $content, array $ops, array $resolve = []): array
    {
        $conflicts = [];
        // structure first, so "set" operations find their items
        usort($ops, fn($a, $b) => ($a['op'] === 'list' ? 0 : 1) <=> ($b['op'] === 'list' ? 0 : 1));
        foreach ($ops as $op) {
            $key = self::key($op['path']);
            if ($op['op'] === 'list') {
                $list = self::get($content, $op['path']);
                if (!is_array($list)) $list = [];
                $cur = [];
                foreach ($list as $it) $cur[$it['_id']] = $it;
                $removed = array_diff($op['old'] ?? [], $op['order']);
                $new = [];
                foreach ($op['order'] as $id) {
                    if (isset($cur[$id])) $new[] = $cur[$id];
                    elseif (isset($op['add'][$id])) $new[] = $op['add'][$id];
                    // an item someone else deleted stays deleted
                }
                // items someone else added meanwhile are kept, at the end
                foreach ($list as $it) {
                    if (!in_array($it['_id'], $op['order'], true) && !in_array($it['_id'], $removed, true)) $new[] = $it;
                }
                $content = self::put($content, $op['path'], $new);
                continue;
            }
            // the item this spot belongs to was deleted meanwhile (a missing field is just empty)
            if (!self::itemExists($content, $op['path'])) { $conflicts[] = ['key' => $key, 'why' => 'gone', 'op' => $op]; continue; }
            $now = self::get($content, $op['path']);
            $changedMeanwhile = $now !== ($op['old'] ?? null) && $now !== $op['value'];
            if ($changedMeanwhile) {
                $r = $resolve[$key] ?? null;
                if ($r === 'theirs') continue;
                if ($r !== 'mine') { $conflicts[] = ['key' => $key, 'why' => 'changed', 'op' => $op, 'theirs' => $now]; continue; }
            }
            $content = self::put($content, $op['path'], $op['value']);
        }
        return [$content, $conflicts];
    }

    private static function itemExists(array $content, array $path): bool
    {
        $last = -1;
        foreach ($path as $i => $seg) if (is_array($seg)) $last = $i;
        if ($last < 0) return true;
        self::get($content, array_slice($path, 0, $last + 1), $found);
        return $found;
    }

    /** Value at a change path (null when missing; $found tells which). */
    public static function get($content, array $path, ?bool &$found = null)
    {
        $found = true;
        $cur = $content;
        foreach ($path as $seg) {
            if (is_array($seg)) {
                $hit = null;
                if (is_array($cur)) foreach ($cur as $it) if (is_array($it) && ($it['_id'] ?? null) === $seg['id']) { $hit = $it; break; }
                if ($hit === null) { $found = false; return null; }
                $cur = $hit;
            } else {
                if (!is_array($cur) || !array_key_exists($seg, $cur)) { $found = false; return null; }
                $cur = $cur[$seg];
            }
        }
        return $cur;
    }

    /** Content with the value at a change path replaced (null removes an optional field). */
    public static function put(array $content, array $path, $value): array
    {
        if (!$path) return is_array($value) ? $value : $content;
        $seg = array_shift($path);
        if (is_array($seg)) {
            foreach ($content as $i => $it) {
                if (is_array($it) && ($it['_id'] ?? null) === $seg['id']) {
                    $content[$i] = $path ? self::put($it, $path, $value) : $value;
                    return $content;
                }
            }
            return $content;
        }
        if (!$path) {
            if ($value === null) unset($content[$seg]);
            else $content[$seg] = $value;
            return $content;
        }
        $content[$seg] = self::put(is_array($content[$seg] ?? null) ? $content[$seg] : [], $path, $value);
        return $content;
    }

    /**
     * Keep only operations on spots the schema knows (her phone sends them; nothing else may be
     * written into the content), with sane values. Unknown or broken operations are dropped.
     */
    public static function sanitize(array $ops, array $schema): array
    {
        $fields = $schema['fields'] ?? [];
        $isList = fn(string $sp) => in_array($fields[$sp]['type'] ?? '', ['list', 'checklist'], true);
        $allowed = function (string $sp) use ($fields, $isList): bool {
            if (isset($fields[$sp])) return true;
            if (preg_match('/^(.*)\[\]\.hidden$/', $sp, $m) && $isList($m[1])) return true;
            // a part of an address, a person, a guarantee or a number
            $pre = $sp;
            while (($dot = strrpos($pre, '.')) !== false) {
                $pre = substr($pre, 0, $dot);
                if (in_array($fields[$pre]['type'] ?? '', ['address', 'person', 'guarantee', 'number-quote'], true)) return true;
            }
            return false;
        };
        $okValue = function ($v, int $depth = 0) use (&$okValue): bool {
            if ($depth > 8) return false;
            if ($v === null || is_bool($v) || is_int($v) || is_float($v)) return true;
            if (is_string($v)) return strlen($v) <= 20000;
            if (!is_array($v) || count($v) > 200) return false;
            foreach ($v as $k => $x) if (!(is_int($k) || (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $k))) || !$okValue($x, $depth + 1)) return false;
            return true;
        };
        $okPath = function ($path): bool {
            if (!is_array($path) || !$path || count($path) > 12) return false;
            foreach ($path as $seg) {
                if (is_string($seg) && preg_match('/^[A-Za-z0-9_]{1,40}$/', $seg)) continue;
                if (is_array($seg) && isset($seg['id']) && is_string($seg['id']) && preg_match('/^[a-z0-9]{1,16}$/', $seg['id']) && count($seg) === 1) continue;
                return false;
            }
            return true;
        };
        $okItem = function ($item, string $listSp) use ($fields, $allowed, $okValue): bool {
            if (!is_array($item) || array_is_list($item) || !isset($item['_id']) || !preg_match('/^[a-z0-9]{1,16}$/', (string)$item['_id'])) return false;
            foreach ($item as $k => $v) {
                if ($k === '_id') continue;
                if (!$allowed("{$listSp}[].$k") || !$okValue($v)) return false;
            }
            return true;
        };
        $out = [];
        foreach ($ops as $op) {
            if (!is_array($op) || !$okPath($op['path'] ?? null)) continue;
            $sp = self::schemaPath($op['path']);
            if (($op['op'] ?? '') === 'set') {
                if (!$allowed($sp) || !$okValue($op['value'] ?? null) || !$okValue($op['old'] ?? null)) continue;
                $out[] = ['op' => 'set', 'path' => $op['path'], 'value' => $op['value'] ?? null, 'old' => $op['old'] ?? null];
            } elseif (($op['op'] ?? '') === 'list') {
                if (!$isList($sp)) continue;
                $ids = fn($l) => is_array($l) && array_is_list($l) && count($l) <= 200 && !array_filter($l, fn($x) => !is_string($x) || !preg_match('/^[a-z0-9]{1,16}$/', $x));
                if (!$ids($op['order'] ?? null) || !$ids($op['old'] ?? [])) continue;
                $add = [];
                $bad = false;
                foreach ((array)($op['add'] ?? []) as $id => $item) {
                    if (!$okItem($item, $sp) || (string)$item['_id'] !== (string)$id) { $bad = true; break; }
                    $add[(string)$id] = $item;
                }
                if ($bad) continue;
                $out[] = ['op' => 'list', 'path' => $op['path'], 'order' => array_values($op['order']), 'add' => $add, 'old' => array_values($op['old'] ?? [])];
            }
        }
        return $out;
    }

    /** Change paths in numeric form ("faq.items.3.q") for a set of operations, against content. */
    public static function dotted(array $content, array $path): string
    {
        $out = [];
        $cur = $content;
        foreach ($path as $seg) {
            if (is_array($seg)) {
                $idx = null;
                foreach ($cur as $i => $it) if (is_array($it) && ($it['_id'] ?? null) === $seg['id']) { $idx = $i; break; }
                if ($idx === null) return '';
                $out[] = (string)$idx;
                $cur = $cur[$idx];
            } else {
                $out[] = $seg;
                $cur = $cur[$seg] ?? null;
            }
        }
        return implode('.', $out);
    }
}
