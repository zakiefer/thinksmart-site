<?php
// A read-only element tree over raw HTML with byte offsets, plus a small selector engine.
// Used only by the converter; nothing here is re-serialized.
declare(strict_types=1);

namespace ThinkSmartDev;

use ThinkSmart\Html;

final class Node
{
    public string $tag;
    public array $attrs = [];
    /** start tag [s, e) */
    public int $s0 = 0, $e0 = 0;
    /** end tag [s, e); equal to e0 for void elements */
    public int $s1 = 0, $e1 = 0;
    /** @var list<Node|array> children: Node or ['text', s, e] */
    public array $kids = [];
    public ?Node $parent = null;
    public Doc $doc;

    public function inner(): string { return substr($this->doc->src, $this->e0, $this->s1 - $this->e0); }
    public function outer(): string { return substr($this->doc->src, $this->s0, $this->e1 - $this->s0); }
    public function attr(string $n): ?string { return $this->attrs[$n] ?? null; }

    /** Decoded text content, whitespace collapsed. */
    public function text(): string
    {
        $t = '';
        foreach ($this->kids as $k) {
            if ($k instanceof Node) $t .= $k->text();
            else $t .= Html::decode(substr($this->doc->src, $k[1], $k[2] - $k[1]));
        }
        return $t;
    }

    public function textTrim(): string { return trim(preg_replace('/\s+/u', ' ', $this->text())); }

    /** @return list<Node> */
    public function find(string $sel): array { return $this->doc->select($sel, $this); }

    public function one(string $sel): Node
    {
        $r = $this->find($sel);
        if (count($r) !== 1) throw new \RuntimeException("expected one '$sel' under <{$this->tag}>, found " . count($r));
        return $r[0];
    }

    /** @return list<Node> element children */
    public function children(): array { return array_values(array_filter($this->kids, fn($k) => $k instanceof Node)); }

    /** Byte range of the only text child (trimmed of surrounding whitespace). */
    public function textRange(): array
    {
        $texts = array_values(array_filter($this->kids, fn($k) => !($k instanceof Node)));
        if (count($texts) !== 1 || count($this->kids) !== 1) throw new \RuntimeException("<{$this->tag}> does not hold just text: " . substr($this->outer(), 0, 120));
        [, $s, $e] = $texts[0];
        $raw = substr($this->doc->src, $s, $e - $s);
        $l = strlen($raw) - strlen(ltrim($raw));
        $r = strlen($raw) - strlen(rtrim($raw));
        return [$s + $l, $e - $r];
    }
}

final class Doc
{
    private const VOID = ['img', 'input', 'meta', 'link', 'br', 'wbr', 'hr', 'source', 'area', 'base', 'col', 'embed', 'param', 'track'];
    public string $src;
    public Node $root;

    public function __construct(string $src)
    {
        $this->src = $src;
        $root = new Node();
        $root->tag = '#root';
        $root->doc = $this;
        $root->s0 = $root->e0 = 0;
        $root->s1 = $root->e1 = strlen($src);
        $stack = [$root];
        foreach (Html::tokenize($src) as $t) {
            $top = $stack[count($stack) - 1];
            if ($t['t'] === 'tag') {
                $n = new Node();
                $n->doc = $this;
                $n->tag = $t['name'];
                $n->attrs = Html::attrs($src, $t);
                $n->s0 = $t['s'];
                $n->e0 = $t['e'];
                $n->parent = $top;
                $top->kids[] = $n;
                if (in_array($t['name'], self::VOID, true) || $t['self']) {
                    $n->s1 = $n->e1 = $t['e'];
                } else {
                    $stack[] = $n;
                }
            } elseif ($t['t'] === 'end') {
                for ($k = count($stack) - 1; $k > 0; $k--) {
                    if ($stack[$k]->tag === $t['name']) {
                        for ($j = count($stack) - 1; $j > $k; $j--) { // implicitly closed
                            $stack[$j]->s1 = $stack[$j]->e1 = $t['s'];
                        }
                        $stack[$k]->s1 = $t['s'];
                        $stack[$k]->e1 = $t['e'];
                        array_splice($stack, $k);
                        break;
                    }
                }
            } elseif ($t['t'] === 'text') {
                $top->kids[] = ['text', $t['s'], $t['e']];
            }
        }
        $this->root = $root;
    }

    /** @return list<Node> */
    public function find(string $sel): array { return $this->select($sel, $this->root); }

    public function one(string $sel): Node { return $this->root->one($sel); }

    /**
     * Selector: steps separated by ' ' (descendant) or ' > ' (child). A step is
     * tag?([attr] | [attr="v"] | [attr*="v"] | [attr^="v"])*(:text("…") | :has-text("…") | :starts("…"))?
     */
    public function select(string $sel, Node $from): array
    {
        $steps = $this->parseSel($sel);
        $cur = [$from];
        foreach ($steps as [$comb, $step]) {
            $next = [];
            $seen = [];
            foreach ($cur as $base) {
                $pool = $comb === '>' ? $base->children() : $this->descendants($base);
                foreach ($pool as $n) {
                    $id = spl_object_id($n);
                    if (isset($seen[$id]) || !$this->matches($n, $step)) continue;
                    $seen[$id] = true;
                    $next[] = $n;
                }
            }
            usort($next, fn($a, $b) => $a->s0 <=> $b->s0);
            $cur = $next;
        }
        return $cur;
    }

    private function descendants(Node $n): array
    {
        $out = [];
        foreach ($n->children() as $c) {
            $out[] = $c;
            foreach ($this->descendants($c) as $d) $out[] = $d;
        }
        return $out;
    }

    private function parseSel(string $sel): array
    {
        $steps = [];
        $comb = ' ';
        $i = 0;
        $n = strlen($sel);
        while ($i < $n) {
            if ($sel[$i] === ' ') { $i++; continue; }
            if ($sel[$i] === '>') { $comb = '>'; $i++; continue; }
            // read one step (quotes may contain spaces)
            $j = $i;
            $q = '';
            while ($j < $n) {
                $c = $sel[$j];
                if ($q !== '') { if ($c === $q) $q = ''; $j++; continue; }
                if ($c === '"') { $q = '"'; $j++; continue; }
                if ($c === ' ' || $c === '>') break;
                $j++;
            }
            $steps[] = [$comb, $this->parseStep(substr($sel, $i, $j - $i))];
            $comb = ' ';
            $i = $j;
        }
        return $steps;
    }

    private function parseStep(string $s): array
    {
        preg_match('/^([a-z0-9-]*|\*)/', $s, $m);
        $step = ['tag' => $m[1] === '*' ? '' : $m[1], 'attrs' => [], 'text' => null];
        $rest = substr($s, strlen($m[1]));
        while ($rest !== '') {
            if (preg_match('/^\[([a-z0-9-]+)(?:(\*=|\^=|=)"([^"]*)")?\]/', $rest, $m)) {
                $step['attrs'][] = [$m[1], $m[2] ?? '', $m[3] ?? ''];
                $rest = substr($rest, strlen($m[0]));
            } elseif (preg_match('/^:(text|has-text|starts)\("([^"]*)"\)/', $rest, $m)) {
                $step['text'] = [$m[1], $m[2]];
                $rest = substr($rest, strlen($m[0]));
            } else {
                throw new \RuntimeException("bad selector step '$s'");
            }
        }
        return $step;
    }

    private function matches(Node $n, array $st): bool
    {
        if ($st['tag'] !== '' && $n->tag !== $st['tag']) return false;
        foreach ($st['attrs'] as [$a, $op, $v]) {
            if (!array_key_exists($a, $n->attrs)) return false;
            $av = (string)$n->attrs[$a];
            if ($op === '=' && $av !== $v) return false;
            if ($op === '*=' && strpos($av, $v) === false) return false;
            if ($op === '^=' && strpos($av, $v) !== 0) return false;
        }
        if ($st['text'] !== null) {
            [$k, $v] = $st['text'];
            $t = $n->textTrim();
            if ($k === 'text' && $t !== $v) return false;
            if ($k === 'has-text' && strpos($t, $v) === false) return false;
            if ($k === 'starts' && strpos($t, $v) !== 0) return false;
        }
        return true;
    }
}
