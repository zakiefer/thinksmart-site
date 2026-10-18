<?php
// Turns today's pages into templates + content without ever re-serializing HTML.
//
// 1. Spots are marked on the raw file by byte range (Dom gives the ranges). Each mark knows
//    its key, filters and decoded value, and is proven to encode back to the same bytes.
// 2. Lists are folded: the item template is rendered once per item with markers in place of
//    values, and must equal the marked text of that item exactly.
// 3. Shared blocks (top and bottom of every page) are swapped for a partial the same way.
// 4. Gate 1 renders every template with the content and compares bytes with today's file.
declare(strict_types=1);

namespace ThinkSmartDev;

use ThinkSmart\BuildError;
use ThinkSmart\Encode;
use ThinkSmart\Html;
use ThinkSmart\Rich;
use ThinkSmart\Template;
use ThinkSmart\Text;

final class Converter
{
    public array $content = [];
    /** key => [label, type, extra] collected for the schema */
    public array $labels = [];
    /** @var array<string, PageConv> */
    public array $pages = [];
    public string $baseDir;
    public array $partials = [];
    /** chip name => how it reads on today's site (used to find chips inside text) */
    public array $chips = [];

    public function __construct(string $baseDir)
    {
        $this->baseDir = $baseDir;
    }

    public function page(string $name, string $file): PageConv
    {
        $src = (string)file_get_contents($this->baseDir . '/' . $file);
        return $this->pages[$name] = new PageConv($this, $name, $file, $src);
    }

    /** Store a value; a key seen before must hold the same value. */
    public function put(string $key, $value, string $where): void
    {
        $cur = $this->get($key);
        if ($cur !== null && $cur !== $value) {
            throw new BuildError("$where: '$key' is " . json_encode($cur, JSON_UNESCAPED_UNICODE) . " elsewhere but " . json_encode($value, JSON_UNESCAPED_UNICODE) . " here");
        }
        $ref = &$this->content;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($ref)) throw new BuildError("$where: '$key' collides with a value");
            if (!array_key_exists($seg, $ref)) $ref[$seg] = [];
            $ref = &$ref[$seg];
        }
        $ref = $value;
    }

    public function get(string $key)
    {
        $cur = $this->content;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) return null;
            $cur = $cur[$seg];
        }
        return $cur;
    }

    public function label(string $key, string $label, string $type = 'text', array $extra = []): void
    {
        $this->labels[$key] = ['label' => $label, 'type' => $type] + $extra;
    }

    /** Arrays keyed exactly 0..n-1 (in any order) become lists, recursively. */
    public static function listify($v)
    {
        if (!is_array($v)) return $v;
        $out = [];
        foreach ($v as $k => $x) $out[$k] = self::listify($x);
        $keys = array_keys($out);
        $n = count($out);
        if ($n > 0 && !array_filter($keys, fn($k) => !is_int($k)) && min($keys) === 0 && max($keys) === $n - 1) {
            ksort($out);
            return array_values($out);
        }
        return $out;
    }
}

final class PageConv
{
    public Converter $cv;
    public string $name;
    public string $file;
    public string $raw;
    public Doc $dom;
    /** pending marks: [s, e, marker, key] on the raw text */
    private array $marks = [];
    /** the marked template once marks are applied */
    public ?string $tpl = null;

    public function __construct(Converter $cv, string $name, string $file, string $raw)
    {
        $this->cv = $cv;
        $this->name = $name;
        $this->file = $file;
        $this->raw = $raw;
        $this->dom = new Doc($raw);
    }

    // ------------------------------------------------------------ finding

    public function one(string $sel): Node { return $this->dom->one($sel); }
    /** @return list<Node> */
    public function all(string $sel): array { return $this->dom->find($sel); }

    // ------------------------------------------------------------ marking

    /**
     * Mark the text of an element that holds only text. $filters like 'upper'. $chips names
     * settings that may appear inside (e.g. ['phone']), stored as locked chips.
     */
    public function text(Node $n, string $key, string $filters = '', array $chips = [], ?string $label = null): void
    {
        [$s, $e] = $n->textRange();
        $this->textRange($s, $e, $key, $filters, $chips, $label);
    }

    /** Mark the text of every element in $nodes with the same key (they must agree). */
    public function textAll(array $nodes, string $key, string $filters = '', array $chips = [], ?string $label = null): void
    {
        if (!$nodes) throw new BuildError("{$this->name}: nothing to mark for $key");
        foreach ($nodes as $n) $this->text($n, $key, $filters, $chips, $label);
    }

    public function textRange(int $s, int $e, string $key, string $filters = '', array $chips = [], ?string $label = null, bool $inDc = true): void
    {
        $raw = substr($this->raw, $s, $e - $s);
        $plain = Html::decode($raw);
        $caps = in_array('upper', explode('|', $filters), true);
        $value = $chips ? $this->chipRuns($plain, $chips, $caps, $key) : $plain;
        $this->mark($s, $e, $key, $filters, $value, $label ?? '', 'text');
    }

    /** Mark the whole inner HTML of an element as words with marks/chips in a named style. */
    public function rich(Node $n, string $key, string $style, array $chips = [], ?string $label = null, string $filters = ''): void
    {
        $inner = $n->inner();
        $lead = strlen($inner) - strlen(ltrim($inner));
        $raw = trim($inner);
        $chipVals = [];
        foreach ($chips as $c) $chipVals[$c] = $this->chipValue($c);
        $runs = Rich::parse($raw, $style, $chipVals, "{$this->name} $key");
        $value = count($runs) === 1 && is_string($runs[0]) ? $runs[0] : $runs;
        $f = ($filters !== '' ? $filters . '|' : '') . 'rich:' . $style;
        $this->mark($n->e0 + $lead, $n->e0 + $lead + strlen($raw), $key, $f, $value, $label ?? '', 'rich');
    }

    /** Mark an attribute value. */
    public function attr(Node $n, string $attr, string $key, string $filters = '', array $chips = [], ?string $label = null): void
    {
        $src = $this->raw;
        // find the attribute's value range in the start tag
        foreach (Html::tokenize(substr($src, $n->s0, $n->e0 - $n->s0)) as $t) {
            if ($t['t'] !== 'tag') continue;
            foreach ($t['attrs'] as [$an, , $vs, $ve]) {
                if (strtolower($an) !== $attr) continue;
                $raw = substr($src, $n->s0 + $vs, $ve - $vs);
                $plain = Html::decode($raw);
                $caps = in_array('upper', explode('|', $filters), true);
                $value = $chips ? $this->chipRuns($plain, $chips, $caps, $key) : $plain;
                $this->mark($n->s0 + $vs, $n->s0 + $ve, $key, $filters, $value, $label ?? '', 'attr');
                return;
            }
        }
        throw new BuildError("{$this->name}: <{$n->tag}> has no $attr for $key");
    }

    /** Mark a JavaScript string literal (quotes included) inside the page script. */
    public function js(string $literal, string $key, array $chips = [], ?string $label = null, int $expect = 1, string $filters = ''): void
    {
        $script = $this->one('script[data-dc-script]');
        $from = $script->e0;
        $to = $script->s1;
        $hits = [];
        $pos = $from;
        while (($at = strpos($this->raw, $literal, $pos)) !== false && $at < $to) { $hits[] = $at; $pos = $at + 1; }
        if (count($hits) !== $expect) throw new BuildError("{$this->name}: JS literal $literal found " . count($hits) . " times, expected $expect");
        $q = $literal[0];
        $body = substr($literal, 1, -1);
        if (($q !== "'" && $q !== '"') || substr($literal, -1) !== $q || strpos($body, '\\') !== false) throw new BuildError("{$this->name}: unsupported JS literal $literal");
        $value = $chips ? $this->chipRuns($body, $chips, false, $key) : $body;
        // keep today's quote style where it differs from the encoder's own choice
        $js = \ThinkSmart\Encode::js($body)[0] === $q ? 'js' : ($q === '"' ? 'js:dq' : 'js:sq');
        foreach ($hits as $at) $this->mark($at, $at + strlen($literal), $key, ($filters !== '' ? $filters . '|' : '') . $js, $value, $label ?? '', 'js');
    }

    /** Replace a raw range with literal template text (no content), e.g. for derived values. */
    public function swap(int $s, int $e, string $tplText, string $why): void
    {
        $this->marks[] = [$s, $e, $tplText, null, $why];
    }

    /** Mark part of an element's only text; $sub (decoded) must appear once in it. */
    public function textSub(Node $n, string $sub, string $key, string $filters = '', array $chips = [], ?string $label = null): void
    {
        [$s, $e] = $n->textRange();
        [$a, $b] = $this->subRange($s, $e, Encode::text($sub, false), $key);
        $this->textRange($a, $b, $key, $filters, $chips, $label);
    }

    /** Mark the $k-th text child (trimmed) of an element that mixes text and tags. */
    public function textChild(Node $n, int $k, string $key, string $filters = '', array $chips = [], ?string $label = null): void
    {
        [$s, $e] = $this->childRange($n, $k, $key);
        $this->textRange($s, $e, $key, $filters, $chips, $label);
    }

    /** Swap an element's only text for template text (Gate 1 proves it renders the same). */
    public function swapText(Node $n, string $tpl, ?string $sub = null): void
    {
        [$s, $e] = $n->textRange();
        if ($sub !== null) [$s, $e] = $this->subRange($s, $e, Encode::text($sub, false), $tpl);
        $this->swap($s, $e, $tpl, 'text');
    }

    /** Swap the $k-th text child for template text. */
    public function swapTextChild(Node $n, int $k, string $tpl): void
    {
        [$s, $e] = $this->childRange($n, $k, $tpl);
        $this->swap($s, $e, $tpl, 'text');
    }

    /** Swap part ($sub, raw) of an attribute value for template text. */
    public function swapAttr(Node $n, string $attr, string $sub, string $tpl): void
    {
        foreach (Html::tokenize(substr($this->raw, $n->s0, $n->e0 - $n->s0)) as $t) {
            if ($t['t'] !== 'tag') continue;
            foreach ($t['attrs'] as [$an, , $vs, $ve]) {
                if (strtolower($an) !== $attr) continue;
                [$a, $b] = $this->subRange($n->s0 + $vs, $n->s0 + $ve, $sub, $tpl);
                $this->swap($a, $b, $tpl, 'attr');
                return;
            }
        }
        throw new BuildError("{$this->name}: <{$n->tag}> has no $attr");
    }

    /** Swap a whole element (or any unique raw range found by text) for template text. */
    public function swapNode(Node $n, string $tpl): void
    {
        $this->swap($n->s0, $n->e1, $tpl, 'node');
    }

    public function swapInner(Node $n, string $tpl): void
    {
        $this->swap($n->e0, $n->s1, $tpl, 'inner');
    }

    private function subRange(int $s, int $e, string $rawSub, string $why): array
    {
        $hay = substr($this->raw, $s, $e - $s);
        $at = strpos($hay, $rawSub);
        if ($at === false || strpos($hay, $rawSub, $at + 1) !== false) throw new BuildError("{$this->name}: '$rawSub' must appear once in '" . substr($hay, 0, 100) . "' ($why)");
        return [$s + $at, $s + $at + strlen($rawSub)];
    }

    private function childRange(Node $n, int $k, string $why): array
    {
        $texts = array_values(array_filter($n->kids, fn($c) => !($c instanceof Node) && trim(substr($this->raw, $c[1], $c[2] - $c[1])) !== ''));
        if (!isset($texts[$k])) throw new BuildError("{$this->name}: <{$n->tag}> has no text #$k ($why)");
        [, $s, $e] = $texts[$k];
        $raw = substr($this->raw, $s, $e - $s);
        return [$s + strlen($raw) - strlen(ltrim($raw)), $e - (strlen($raw) - strlen(rtrim($raw)))];
    }

    /** Find a unique raw substring between $from and $to (byte offsets). */
    public function locate(string $needle, int $from = 0, ?int $to = null, int $expect = 1): array
    {
        $to = $to ?? strlen($this->raw);
        $hits = [];
        $pos = $from;
        while (($at = strpos($this->raw, $needle, $pos)) !== false && $at + strlen($needle) <= $to) { $hits[] = $at; $pos = $at + 1; }
        if (count($hits) !== $expect) throw new BuildError("{$this->name}: '" . substr($needle, 0, 80) . "' found " . count($hits) . " times, expected $expect");
        return $hits;
    }

    private function mark(int $s, int $e, string $key, string $filters, $value, string $label, string $kind): void
    {
        $marker = Template::OPEN . $key . ($filters !== '' ? '|' . $filters : '') . Template::CLOSE;
        $this->marks[] = [$s, $e, $marker, $key, $value, $label, $kind, $filters];
    }

    public function chipValue(string $name): string
    {
        $v = $this->cv->chips[$name] ?? null;
        if ($v === null) throw new BuildError("unknown chip $name");
        return $v;
    }

    private function chipRuns(string $plain, array $chips, bool $caps, string $key): array|string
    {
        $alts = [];
        $vals = [];
        foreach ($chips as $c) $vals[$c] = $this->chipValue($c);
        uasort($vals, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($vals as $c => $v) $alts[] = preg_quote($caps ? Text::upper($v) : $v, '/');
        $re = '/(' . implode('|', $alts) . ')/u';
        $parts = preg_split($re, $plain, -1, PREG_SPLIT_DELIM_CAPTURE);
        $runs = [];
        foreach ($parts as $i => $p) {
            if ($i % 2 === 0) { if ($p !== '') $runs[] = $p; continue; }
            foreach ($vals as $c => $v) if (($caps ? Text::upper($v) : $v) === $p) { $runs[] = ['ref' => $c]; break; }
        }
        if (!array_filter($runs, 'is_array')) return $plain; // no chip in this one
        return $runs;
    }

    /** Apply all marks to the raw text; values go to the converter's content. */
    public function apply(): void
    {
        // a rich spot owns everything inside it (its links are rebuilt from its chips)
        $rich = array_values(array_filter($this->marks, fn($m) => ($m[6] ?? '') === 'rich'));
        $this->marks = array_values(array_filter($this->marks, function ($m) use ($rich) {
            if (($m[6] ?? '') === 'rich') return true;
            foreach ($rich as $r) if ($m[0] >= $r[0] && $m[1] <= $r[1]) return false;
            return true;
        }));
        usort($this->marks, fn($a, $b) => $a[0] <=> $b[0]);
        $out = '';
        $pos = 0;
        $this->map = [];
        foreach ($this->marks as $m) {
            [$s, $e, $marker, $key] = $m;
            if ($s < $pos) throw new BuildError("{$this->name}: overlapping spots at byte $s (" . ($key ?? $m[4]) . ")");
            $out .= substr($this->raw, $pos, $s - $pos);
            $this->map[] = [$s, $e, strlen($out), strlen($out) + strlen($marker)];
            $out .= $marker;
            $pos = $e;
            if ($key !== null) {
                $this->cv->put($key, $m[4], $this->name);
                if ($m[5] !== '') $this->cv->label($key, $m[5], $m[6]);
            }
        }
        $this->tpl = $out . substr($this->raw, $pos);
        $this->marks = [];
        // lists and hand-written blocks, last first so earlier offsets stay valid
        usort($this->blocks, fn($a, $b) => $b[0] <=> $a[0]);
        $prevStart = PHP_INT_MAX;
        foreach ($this->blocks as $blk) {
            if ($blk[1] > $prevStart) throw new BuildError("{$this->name}: overlapping blocks");
            $prevStart = $blk[0];
            $this->applyBlock($blk);
        }
        $this->blocks = [];
    }

    /** raw => marked offsets for applied marks */
    private array $map = [];
    /** pending list folds and hand-written blocks: [rawStart, rawEnd, kind, ...] */
    private array $blocks = [];

    /** Where a raw offset (outside any mark) sits in the marked template. */
    private function mapped(int $raw): int
    {
        $delta = 0;
        foreach ($this->map as [$s, $e, $ms, $me]) {
            if ($e <= $raw) $delta += ($me - $ms) - ($e - $s);
            elseif ($s < $raw) throw new BuildError("{$this->name}: offset $raw is inside a spot");
        }
        return $raw + $delta;
    }

    /** Raw range of whole lines covering the given nodes (or exact range when $inline). */
    private function lines(array $nodes, bool $inline, bool $blankAfter = false): array
    {
        $s = $nodes[0]->s0;
        $e = $nodes[count($nodes) - 1]->e1;
        if ($inline) return [$s, $e];
        $ls = strrpos(substr($this->raw, 0, $s), "\n");
        $ls = $ls === false ? 0 : $ls + 1;
        if (trim(substr($this->raw, $ls, $s - $ls)) !== '') throw new BuildError("{$this->name}: list item doesn't start its line");
        $le = strpos($this->raw, "\n", $e);
        if ($le === false || trim(substr($this->raw, $e, $le - $e)) !== '') throw new BuildError("{$this->name}: list item doesn't end its line");
        if ($blankAfter) {
            if (($this->raw[$le + 1] ?? '') !== "\n") throw new BuildError("{$this->name}: no blank line after the list");
            $le++;
        }
        return [$ls, $le + 1];
    }

    /** Raw range of the whole line holding a node. */
    public function lineOf(Node $n): array
    {
        return $this->lines([$n], false);
    }

    /**
     * Fold item nodes into a list. Every value inside must already be marked with keys
     * "$listKey.$i.…" (or be a chip/setting); $itemTpl uses "$alias." for the item. Whole
     * lines are folded unless $inline.
     */
    public function foldNodes(array $nodes, string $listKey, string $itemTpl, string $alias = 'x', bool $inline = false, bool $blankAfter = false): void
    {
        if (!$nodes) throw new BuildError("{$this->name}: no items for $listKey");
        [$s, $e] = $this->lines($nodes, $inline, $blankAfter);
        $this->blocks[] = [$s, $e, 'fold', $listKey, $itemTpl, $alias];
    }

    /** Replace nodes by hand-written template text; content is put by the recipe; Gate 1 checks it. */
    public function blockNodes(array $nodes, string $tpl, bool $inline = false): void
    {
        [$s, $e] = $this->lines($nodes, $inline);
        $this->blocks[] = [$s, $e, 'swap', $tpl];
    }

    public function blockRange(int $s, int $e, string $tpl): void
    {
        $this->blocks[] = [$s, $e, 'swap', $tpl];
    }

    private function applyBlock(array $blk): void
    {
        $from = $this->mapped($blk[0]);
        $to = $this->mapped($blk[1]);
        $chunk = substr((string)$this->tpl, $from, $to - $from);
        if ($blk[2] === 'swap') {
            $this->tpl = substr($this->tpl, 0, $from) . $blk[3] . substr($this->tpl, $to);
            return;
        }
        [, , , $listKey, $itemTpl, $alias] = $blk;
        $this->cv->content = Converter::listify($this->cv->content);
        $list = $this->cv->get($listKey);
        if (!is_array($list) || !array_is_list($list)) throw new BuildError("{$this->name}: $listKey has no items in order");
        $expected = MarkerRender::each($itemTpl, $listKey, $alias, $list, $this->cv->content + ['page' => $this->name]);
        if ($expected !== $chunk) throw new BuildError("{$this->name}: list $listKey does not fold.\n" . self::firstDiff($chunk, $expected));
        $o = Template::OPEN;
        $c = Template::CLOSE;
        $block = $inlineBlock = "$o#each $listKey as $alias$c" . $itemTpl . "$o/each$c";
        if (substr($itemTpl, -1) === "\n") {
            // standalone lines: indent the markers like the items
            preg_match('/^[ \t]*/', $itemTpl, $ind);
            $block = $ind[0] . "$o#each $listKey as $alias$c\n" . $itemTpl . $ind[0] . "$o/each$c\n";
        }
        $this->tpl = substr($this->tpl, 0, $from) . $block . substr($this->tpl, $to);
    }

    // ------------------------------------------------------------ folding lists

    /**
     * Fold the marked text between two unique anchors (in the marked template) into a list.
     * $itemTpl is the template of one item using "x." for the item, the list's items must
     * already be marked with keys "$listKey.$i.field". The anchors stay in place.
     */
    public function fold(string $startAnchor, string $endAnchor, string $listKey, string $itemTpl, string $alias = 'x', array $extraRoot = []): void
    {
        $tpl = (string)$this->tpl;
        $a = $this->uniq($tpl, $startAnchor);
        $from = $a + strlen($startAnchor);
        $b = strpos($tpl, $endAnchor, $from);
        if ($b === false) throw new BuildError("{$this->name}: end anchor not found for $listKey");
        $chunk = substr($tpl, $from, $b - $from);
        $list = $this->cv->get($listKey);
        $list = Converter::listify($list);
        if (!is_array($list)) throw new BuildError("{$this->name}: $listKey has no items");
        $this->cv->put($listKey, null, 'reset');
        $this->setList($listKey, $list);
        $expected = MarkerRender::each($itemTpl, $listKey, $alias, $list, $this->cv->content + $extraRoot + ['page' => $this->name]);
        if ($expected !== $chunk) {
            throw new BuildError("{$this->name}: list $listKey does not fold.\n" . self::firstDiff($chunk, $expected));
        }
        $block = Template::OPEN . "#each $listKey as $alias" . Template::CLOSE . $itemTpl . Template::OPEN . '/each' . Template::CLOSE;
        $this->tpl = substr($tpl, 0, $from) . $block . substr($tpl, $b);
    }

    /** Replace a unique piece of the marked template with a partial, proven equal for this page. */
    public function usePartial(string $startAnchor, string $endAnchor, string $partial, string $partialTpl, bool $includeAnchors = true): void
    {
        $tpl = (string)$this->tpl;
        $a = $this->uniq($tpl, $startAnchor);
        $b = strpos($tpl, $endAnchor, $a + strlen($startAnchor));
        if ($b === false) throw new BuildError("{$this->name}: end anchor for partial $partial not found");
        $from = $includeAnchors ? $a : $a + strlen($startAnchor);
        $to = $includeAnchors ? $b + strlen($endAnchor) : $b;
        $chunk = substr($tpl, $from, $to - $from);
        $expected = MarkerRender::text($partialTpl, $this->cv->content + ['page' => $this->name]);
        if ($expected !== $chunk) {
            throw new BuildError("{$this->name}: partial $partial differs.\n" . self::firstDiff($chunk, $expected));
        }
        $this->tpl = substr($tpl, 0, $from) . Template::OPEN . '>' . $partial . Template::CLOSE . substr($tpl, $to);
    }

    /** Replace a unique piece of the marked template with other template text (proven equal). */
    public function rewrite(string $old, string $newTpl): void
    {
        $tpl = (string)$this->tpl;
        $a = $this->uniq($tpl, $old);
        $expected = MarkerRender::text($newTpl, $this->cv->content + ['page' => $this->name]);
        if ($expected !== $old) throw new BuildError("{$this->name}: rewrite differs.\n" . self::firstDiff($old, $expected));
        $this->tpl = substr($tpl, 0, $a) . $newTpl . substr($tpl, $a + strlen($old));
    }

    private function setList(string $key, array $list): void
    {
        $ref = &$this->cv->content;
        foreach (explode('.', $key) as $seg) $ref = &$ref[$seg];
        $ref = $list;
    }

    private function uniq(string $hay, string $needle): int
    {
        $a = strpos($hay, $needle);
        if ($a === false || strpos($hay, $needle, $a + 1) !== false) throw new BuildError("{$this->name}: anchor must appear once: " . substr($needle, 0, 80));
        return $a;
    }

    public static function firstDiff(string $a, string $b): string
    {
        $n = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $n && $a[$i] === $b[$i]) $i++;
        $from = max(0, $i - 120);
        return "  have: …" . substr($a, $from, 260) . "…\n  want: …" . substr($b, $from, 260) . "…";
    }
}

/**
 * Renders a template the way the converter needs it: values whose path is inside the list
 * become markers with the item's index ("⟦faq.items.3.q|rich:faq⟧"), loop positions and
 * conditions are worked out, and everything else stays as written.
 */
final class MarkerRender
{
    public static function each(string $itemTpl, string $listKey, string $alias, array $list, array $root): string
    {
        $nodes = Template::parse($itemTpl, $listKey);
        $vis = [];
        foreach ($list as $i => $it) if (!(is_array($it) && !empty($it['hidden']))) $vis[] = [$i, $it];
        $out = '';
        $count = count($vis);
        foreach ($vis as $k => [$i, $item]) {
            $frames = [['alias' => $alias, 'item' => $item, 'key' => "$listKey.$i", 'vars' => [
                'index' => $k, 'n' => $k + 1, 'count' => $count, 'first' => $k === 0, 'last' => $k === $count - 1,
                'odd' => $k % 2 === 1, 'even' => $k % 2 === 0]]];
            $out .= self::emit($nodes, $root, $frames);
        }
        return $out;
    }

    public static function text(string $tpl, array $root): string
    {
        return self::emit(Template::parse($tpl, 'partial'), $root, []);
    }

    private static function emit(array $nodes, array $root, array $frames): string
    {
        $out = '';
        foreach ($nodes as $nd) {
            switch ($nd[0]) {
                case 'lit': $out .= $nd[1]; break;
                case 'part': $out .= Template::OPEN . '>' . $nd[1] . Template::CLOSE; break;
                case 'val':
                    $path = $nd[1];
                    $fs = implode('|', array_map(fn($f) => $f[0] . ($f[1] !== null ? ':' . $f[1] : ''), $nd[2]));
                    if ($path[0] === '@') {
                        // positions become literal text
                        $v = self::value($path, $root, $frames);
                        foreach ($nd[2] as [$f, $arg]) $v = \ThinkSmart\Filters::apply($f, $arg, $v, fn() => '', [], 'converter');
                        $out .= (string)$v;
                        break;
                    }
                    $seg = explode('.', $path);
                    $mapped = null;
                    for ($k = count($frames) - 1; $k >= 0; $k--) {
                        if ($seg[0] === $frames[$k]['alias']) { $mapped = $frames[$k]['key'] . (count($seg) > 1 ? '.' . implode('.', array_slice($seg, 1)) : ''); break; }
                    }
                    $out .= Template::OPEN . ($mapped ?? $path) . ($fs !== '' ? '|' . $fs : '') . Template::CLOSE;
                    break;
                case 'if':
                    $out .= self::emit(self::test($nd[1], $root, $frames) ? $nd[2] : $nd[3], $root, $frames);
                    break;
                case 'each':
                    $list = self::value($nd[1], $root, $frames) ?? [];
                    $vis = [];
                    foreach ($list as $i => $it) if (!(is_array($it) && !empty($it['hidden']))) $vis[] = [$i, $it];
                    $count = count($vis);
                    $base = self::keyOf($nd[1], $frames);
                    foreach ($vis as $k => [$i, $item]) {
                        $sub = $frames;
                        $sub[] = ['alias' => $nd[2], 'item' => $item, 'key' => "$base.$i", 'vars' => [
                            'index' => $k, 'n' => $k + 1, 'count' => $count, 'first' => $k === 0, 'last' => $k === $count - 1,
                            'odd' => $k % 2 === 1, 'even' => $k % 2 === 0]];
                        $out .= self::emit($nd[3], $root, $sub);
                    }
                    break;
            }
        }
        return $out;
    }

    private static function keyOf(string $path, array $frames): string
    {
        $seg = explode('.', $path);
        for ($k = count($frames) - 1; $k >= 0; $k--) {
            if ($seg[0] === $frames[$k]['alias']) return $frames[$k]['key'] . (count($seg) > 1 ? '.' . implode('.', array_slice($seg, 1)) : '');
        }
        return $path;
    }

    private static function value(string $path, array $root, array $frames)
    {
        if ($path[0] === '@') {
            $var = substr($path, 1);
            if (strpos($var, 'outer.') === 0) { $var = substr($var, 6); array_pop($frames); }
            $f = end($frames);
            return $f['vars'][$var];
        }
        $seg = explode('.', $path);
        for ($k = count($frames) - 1; $k >= 0; $k--) {
            if ($seg[0] === $frames[$k]['alias']) { $cur = $frames[$k]['item']; array_shift($seg); return self::dig($cur, $seg); }
        }
        return self::dig($root, $seg);
    }

    private static function dig($cur, array $seg)
    {
        foreach ($seg as $s) { if (is_array($cur) && array_key_exists($s, $cur)) $cur = $cur[$s]; else return null; }
        return $cur;
    }

    private static function test(array $e, array $root, array $frames): bool
    {
        switch ($e[0]) {
            case '||': foreach ($e[1] as $x) if (self::test($x, $root, $frames)) return true; return false;
            case '&&': foreach ($e[1] as $x) if (!self::test($x, $root, $frames)) return false; return true;
            case 'truthy':
                $v = Template::truthy(self::value($e[1], $root, $frames));
                return $e[2] ? !$v : $v;
            default:
                $v = self::value($e[1], $root, $frames);
                $w = $e[3];
                switch ($e[2]) {
                    case '==': return is_int($w) ? (int)$v === $w : (string)$v === $w;
                    case '!=': return is_int($w) ? (int)$v !== $w : (string)$v !== $w;
                    case '>': return (float)$v > (float)$w;
                    case '<': return (float)$v < (float)$w;
                    case '>=': return (float)$v >= (float)$w;
                    default: return (float)$v <= (float)$w;
                }
        }
    }
}
