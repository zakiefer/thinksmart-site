<?php
// Page templates: the site's own .dc.html files with ⟦…⟧ markers where content goes.
//
//   ⟦path⟧ ⟦path|filter|filter:arg⟧   a value; the encoder is picked from where it lands
//   ⟦#each path as x⟧ … ⟦/each⟧        a list (items with "hidden": true are skipped);
//                                       inside: x.field, @index @n @count @first @last @odd @even
//   ⟦#if expr⟧ … ⟦#else⟧ … ⟦/if⟧       expr: a, !a, a == 'v', a != 'v', joined by && or ||
//   ⟦>name⟧                            a shared partial (templates/partials/name.html)
//   ⟦!comment⟧
// A block tag alone on its line removes that whole line from the output.
//
// Rendering is two-step so the encoder always matches the real position: values first go in
// as private-use placeholders, the result is tokenized, and each placeholder is then replaced
// by its value encoded for that exact spot (text, attribute, URL, <title>, script literal,
// JSON-LD) inside or outside <x-dc>.
declare(strict_types=1);

namespace ThinkSmart;

final class Template
{
    public const OPEN = "\u{27E6}";
    public const CLOSE = "\u{27E7}";
    private const S0 = "\u{E000}";
    private const S1 = "\u{E001}";

    /** Attributes that hold a URL. */
    private const URL_ATTRS = ['href' => true, 'src' => true, 'poster' => true, 'action' => true];

    private string $dir;
    private array $cache = [];
    /** @var callable(string $ref, array $scope): string */
    private $refResolver;

    public function __construct(string $dir, callable $refResolver)
    {
        $this->dir = rtrim($dir, '/');
        $this->refResolver = $refResolver;
    }

    // ---------------------------------------------------------------- parsing

    public static function parse(string $src, string $name = 'template'): array
    {
        $src = self::stripStandalone($src);
        $pos = 0;
        $stack = [['root', []]];
        $len = strlen($src);
        $ol = strlen(self::OPEN);
        $cl = strlen(self::CLOSE);
        while ($pos < $len) {
            $o = strpos($src, self::OPEN, $pos);
            if ($o === false) {
                $stack[count($stack) - 1][1][] = ['lit', substr($src, $pos)];
                break;
            }
            if ($o > $pos) $stack[count($stack) - 1][1][] = ['lit', substr($src, $pos, $o - $pos)];
            $c = strpos($src, self::CLOSE, $o + $ol);
            if ($c === false) throw new BuildError("$name: unclosed marker at byte $o");
            $body = trim(substr($src, $o + $ol, $c - $o - $ol));
            $pos = $c + $cl;
            $where = "$name: marker ⟦{$body}⟧";
            if ($body === '' ) throw new BuildError("$where is empty");
            if ($body[0] === '!') continue;
            if ($body[0] === '>') {
                $stack[count($stack) - 1][1][] = ['part', trim(substr($body, 1))];
            } elseif (preg_match('/^#each\s+([A-Za-z_@][\w.@-]*)\s+as\s+([A-Za-z_]\w*)$/', $body, $m)) {
                $stack[] = ['each', [], $m[1], $m[2]];
            } elseif (preg_match('/^#if\s+(.+)$/s', $body, $m)) {
                $stack[] = ['if', [], self::parseExpr($m[1], $where), null];
            } elseif ($body === '#else') {
                $top = count($stack) - 1;
                if ($stack[$top][0] !== 'if' || $stack[$top][3] !== null) throw new BuildError("$where without #if");
                $stack[$top][3] = $stack[$top][1];
                $stack[$top][1] = [];
            } elseif ($body === '/each' || $body === '/if') {
                $frame = array_pop($stack);
                if ($frame[0] !== substr($body, 1)) throw new BuildError("$where closes a {$frame[0]}");
                if ($frame[0] === 'each') {
                    $node = ['each', $frame[2], $frame[3], $frame[1]];
                } else {
                    $node = $frame[3] === null ? ['if', $frame[2], $frame[1], []] : ['if', $frame[2], $frame[3], $frame[1]];
                }
                $stack[count($stack) - 1][1][] = $node;
            } else {
                $parts = explode('|', $body);
                $path = trim(array_shift($parts));
                if (!preg_match('/^@?[A-Za-z_][\w.-]*$/', $path)) throw new BuildError("$where: bad path");
                $filters = [];
                foreach ($parts as $f) {
                    $f = trim($f);
                    $k = strpos($f, ':');
                    $filters[] = $k === false ? [$f, null] : [substr($f, 0, $k), substr($f, $k + 1)];
                }
                $stack[count($stack) - 1][1][] = ['val', $path, $filters];
            }
        }
        if (count($stack) !== 1) throw new BuildError("$name: unclosed ⟦#{$stack[count($stack) - 1][0]}⟧");
        return $stack[0][1];
    }

    /** Lines holding only a block marker (and whitespace) disappear entirely. */
    private static function stripStandalone(string $src): string
    {
        $o = preg_quote(self::OPEN, '/');
        $c = preg_quote(self::CLOSE, '/');
        return preg_replace('/^[ \t]*' . $o . '\s*([#\/!>][^' . $c . ']*)' . $c . '[ \t]*\n/mu', self::OPEN . '$1' . self::CLOSE, $src) ?? $src;
    }

    private static function parseExpr(string $s, string $where): array
    {
        $s = trim($s);
        foreach (['||', '&&'] as $op) {
            $parts = self::splitTop($s, $op);
            if (count($parts) > 1) return [$op, array_map(fn($p) => self::parseExpr($p, $where), $parts)];
        }
        if (preg_match('/^(!?)\s*(@?[A-Za-z_][\w.@-]*)$/', $s, $m)) return ['truthy', $m[2], $m[1] === '!'];
        if (preg_match('/^(@?[A-Za-z_][\w.@-]*)\s*(==|!=|>=|<=|>|<)\s*(?:\'([^\']*)\'|(-?\d+))$/', $s, $m)) {
            return ['cmp', $m[1], $m[2], isset($m[4]) && $m[4] !== '' ? (int)$m[4] : $m[3]];
        }
        throw new BuildError("$where: can't read condition '$s'");
    }

    private static function splitTop(string $s, string $op): array
    {
        $parts = [];
        $q = false;
        $start = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            if ($s[$i] === "'") $q = !$q;
            if (!$q && substr($s, $i, 2) === $op) {
                $parts[] = substr($s, $start, $i - $start);
                $start = $i + 2;
                $i++;
            }
        }
        $parts[] = substr($s, $start);
        return $parts;
    }

    public function load(string $name): array
    {
        if (!isset($this->cache[$name])) {
            $file = $this->dir . '/' . $name;
            if (!is_file($file)) throw new BuildError("Missing template $name");
            $this->cache[$name] = self::parse((string)file_get_contents($file), $name);
        }
        return $this->cache[$name];
    }

    /** Parse an in-memory template (used by the converter and tests). */
    public function setSource(string $name, string $src): void
    {
        $this->cache[$name] = self::parse($src, $name);
    }

    // ---------------------------------------------------------------- rendering

    /**
     * Render a template. $root is the content plus page variables (e.g. 'page' => 'Home').
     * Returns the finished file. When $trace is given, it receives one entry per inserted
     * value: [path, context, start, end, stored path] with byte offsets into the result.
     */
    public function render(string $name, array $root, ?array &$trace = null): string
    {
        $slots = [];
        $scope = ['root' => $root, 'frames' => []];
        $draft = $this->emit($this->load($name), $scope, $slots, $name);
        return $this->fill($draft, $slots, $name, $trace);
    }

    private function emit(array $nodes, array $scope, array &$slots, string $name): string
    {
        $out = '';
        foreach ($nodes as $node) {
            switch ($node[0]) {
                case 'lit':
                    $out .= $node[1];
                    break;
                case 'val':
                    $id = count($slots);
                    $slots[] = ['path' => $node[1], 'filters' => $node[2], 'value' => $this->resolve($node[1], $scope, $name), 'scope' => $scope, 'abs' => self::absPath($node[1], $scope)];
                    $out .= self::S0 . $id . self::S1;
                    break;
                case 'part':
                    $out .= $this->emit($this->load('partials/' . $node[1] . '.html'), $scope, $slots, 'partials/' . $node[1]);
                    break;
                case 'if':
                    $out .= $this->emit($this->test($node[1], $scope, $name) ? $node[2] : $node[3], $scope, $slots, $name);
                    break;
                case 'each':
                    $list = $this->resolve($node[1], $scope, $name);
                    if ($list === null) $list = [];
                    if (!is_array($list) || !array_is_list($list)) throw new BuildError("$name: {$node[1]} is not a list");
                    $listAbs = self::absPath($node[1], $scope);
                    $items = [];
                    foreach ($list as $k => $it) if (!(is_array($it) && !empty($it['hidden']))) $items[] = [$k, $it];
                    $count = count($items);
                    foreach ($items as $i => [$k, $item]) {
                        // where the item is stored (for the editor's "Fix a word I saw"); derived
                        // lists name their source item in "_src"
                        $abs = is_array($item) && isset($item['_src']) ? (string)$item['_src'] : ($listAbs === null ? null : "$listAbs.$k");
                        $frame = ['alias' => $node[2], 'item' => $item, 'abs' => $abs, 'vars' => [
                            'index' => $i, 'n' => $i + 1, 'count' => $count, 'first' => $i === 0,
                            'last' => $i === $count - 1, 'odd' => $i % 2 === 1, 'even' => $i % 2 === 0,
                        ]];
                        $sub = $scope;
                        $sub['frames'][] = $frame;
                        $out .= $this->emit($node[3], $sub, $slots, $name);
                    }
                    break;
            }
        }
        return $out;
    }

    public function resolve(string $path, array $scope, string $name)
    {
        if ($path[0] === '@') {
            $var = substr($path, 1);
            $frames = $scope['frames'];
            // "@index" is the innermost loop; "@outer.index" the one around it.
            if (strpos($var, 'outer.') === 0) { $var = substr($var, 6); array_pop($frames); }
            $f = end($frames);
            if ($f === false || !array_key_exists($var, $f['vars'])) throw new BuildError("$name: @$var outside a list");
            return $f['vars'][$var];
        }
        $seg = explode('.', $path);
        for ($k = count($scope['frames']) - 1; $k >= 0; $k--) {
            $f = $scope['frames'][$k];
            if ($seg[0] === $f['alias']) return self::dig($f['item'], array_slice($seg, 1));
        }
        // ⟦ref.phone⟧: a chip on its own (the setting as visitors read it)
        if ($seg[0] === 'ref' && count($seg) === 2) return ($this->refResolver)($seg[1], $scope);
        return self::dig($scope['root'], $seg);
    }

    /**
     * The stored path a template path reads ("x.q" inside faq.items -> "faq.items.3.q"), or null
     * for loop variables. Derived items may map a field to its source with "_edit".
     */
    private static function absPath(string $path, array $scope): ?string
    {
        if ($path[0] === '@') return null;
        $seg = explode('.', $path);
        for ($k = count($scope['frames']) - 1; $k >= 0; $k--) {
            $f = $scope['frames'][$k];
            if ($seg[0] !== $f['alias']) continue;
            $rest = implode('.', array_slice($seg, 1));
            if (is_array($f['item']) && isset($f['item']['_edit'][$rest])) return (string)$f['item']['_edit'][$rest];
            if (($f['abs'] ?? null) === null) return null;
            return $rest === '' ? $f['abs'] : $f['abs'] . '.' . $rest;
        }
        return $path;
    }

    private static function dig($cur, array $seg)
    {
        foreach ($seg as $s) {
            if (is_array($cur) && array_key_exists($s, $cur)) $cur = $cur[$s];
            else return null;
        }
        return $cur;
    }

    private function test(array $e, array $scope, string $name): bool
    {
        switch ($e[0]) {
            case '||':
                foreach ($e[1] as $x) if ($this->test($x, $scope, $name)) return true;
                return false;
            case '&&':
                foreach ($e[1] as $x) if (!$this->test($x, $scope, $name)) return false;
                return true;
            case 'truthy':
                $v = self::truthy($this->resolve($e[1], $scope, $name));
                return $e[2] ? !$v : $v;
            default:
                $v = $this->resolve($e[1], $scope, $name);
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

    public static function truthy($v): bool
    {
        if (is_array($v)) {
            if (!array_is_list($v)) return $v !== [];
            foreach ($v as $it) if (!(is_array($it) && !empty($it['hidden']))) return true;
            return false;
        }
        return !($v === null || $v === false || $v === '' || $v === 0 || $v === '0');
    }

    // ---------------------------------------------------------------- placing values

    private function fill(string $draft, array $slots, string $name, ?array &$trace): string
    {
        if (!$slots) return $draft;
        $where = self::locate($draft);
        $out = '';
        $pos = 0;
        $trace = [];
        $re = '/' . self::S0 . '(\d+)' . self::S1 . '/u';
        preg_match_all($re, $draft, $ms, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($ms as $m) {
            [$whole, $at] = $m[0];
            $id = (int)$m[1][0];
            $ctx = $where[$at] ?? null;
            if ($ctx === null) throw new BuildError("$name: can't tell where {$slots[$id]['path']} lands");
            $out .= substr($draft, $pos, $at - $pos);
            $start = strlen($out);
            $out .= $this->encodeSlot($slots[$id], $ctx, $name);
            $trace[] = [$slots[$id]['path'], $ctx['kind'], $start, strlen($out), $slots[$id]['abs']];
            $pos = $at + strlen($whole);
        }
        return $out . substr($draft, $pos);
    }

    /**
     * Context of every placeholder in a draft, keyed by byte offset:
     *   kind: text | rcdata | attr | url | style-attr | js | json | css | comment
     *   dc: inside <x-dc>, tag/attr names for attributes.
     */
    public static function locate(string $draft): array
    {
        $map = [];
        $toks = Html::tokenize($draft);
        $inDc = false;
        $scriptKind = '';
        $lastTag = null;
        $re = '/' . self::S0 . '\d+' . self::S1 . '/u';
        foreach ($toks as $t) {
            if ($t['t'] === 'tag') {
                $a = Html::attrs($draft, $t);
                foreach ($t['attrs'] as [$an, $ns, $vs, $ve]) {
                    if (preg_match_all($re, substr($draft, $ns, ($vs < 0 ? strlen($an) : $vs - $ns)), $mm, PREG_OFFSET_CAPTURE)) {
                        foreach ($mm[0] as [, $off]) $map[$ns + $off] = ['kind' => 'bad', 'why' => 'inside an attribute name'];
                    }
                    if ($vs < 0) continue;
                    if (!preg_match_all($re, substr($draft, $vs, $ve - $vs), $mm, PREG_OFFSET_CAPTURE)) continue;
                    $lan = strtolower($an);
                    $kind = 'attr';
                    if (isset(self::URL_ATTRS[$lan])) $kind = 'url';
                    elseif ($lan === 'style' || strpos($lan, 'style-') === 0) $kind = 'style-attr';
                    elseif ($t['name'] === 'meta' && $lan === 'content' && in_array($a['property'] ?? '', ['og:url', 'og:image'], true)) $kind = 'url';
                    foreach ($mm[0] as [, $off]) {
                        $map[$vs + $off] = ['kind' => $kind, 'dc' => $inDc, 'tag' => $t['name'], 'attr' => $lan,
                            'whole' => ($ve - $vs) === strlen($mm[0][0][0]) && count($mm[0]) === 1];
                    }
                }
                if ($t['name'] === 'x-dc') $inDc = true;
                if ($t['name'] === 'script') {
                    $type = strtolower((string)($a['type'] ?? ''));
                    $scriptKind = array_key_exists('data-dc-script', $a) ? 'js' : ($type === 'application/ld+json' ? 'json' : 'script');
                }
                $lastTag = $t;
            } elseif ($t['t'] === 'end') {
                if ($t['name'] === 'x-dc') $inDc = false;
            } elseif ($t['t'] === 'text') {
                $raw = substr($draft, $t['s'], $t['e'] - $t['s']);
                if (!preg_match_all($re, $raw, $mm, PREG_OFFSET_CAPTURE)) continue;
                $kind = $t['in'] === '' ? 'text' : ($t['in'] === 'script' ? $scriptKind : ($t['in'] === 'style' ? 'css' : 'rcdata'));
                foreach ($mm[0] as [, $off]) $map[$t['s'] + $off] = ['kind' => $kind, 'dc' => $inDc, 'tag' => $lastTag['name'] ?? ''];
            } elseif ($t['t'] === 'comment' || $t['t'] === 'doctype') {
                if (preg_match_all($re, substr($draft, $t['s'], $t['e'] - $t['s']), $mm, PREG_OFFSET_CAPTURE)) {
                    foreach ($mm[0] as [, $off]) $map[$t['s'] + $off] = ['kind' => 'bad', 'why' => 'inside a comment'];
                }
            }
        }
        return $map;
    }

    private function encodeSlot(array $slot, array $ctx, string $name): string
    {
        $path = $slot['path'];
        $where = "$name: ⟦{$path}⟧";
        if ($ctx['kind'] === 'bad') throw new BuildError("$where sits {$ctx['why']}");
        $v = $slot['value'];
        $encoder = null;
        $post = [];
        foreach ($slot['filters'] as [$f, $arg]) {
            switch ($f) {
                case 'rich': case 'js': case 'json': case 'css': case 'cssurl': case 'jsnum': case 'gencss':
                    $encoder = [$f, $arg];
                    break;
                case 'wbr':
                    $post[] = $f;
                    break;
                default:
                    $v = Filters::apply($f, $arg, $v, $this->refResolver, $slot['scope'], $where);
            }
        }
        $kind = $ctx['kind'];
        if ($encoder !== null) {
            [$e, $arg] = $encoder;
            $ok = ['rich' => ['text'], 'js' => ['js'], 'json' => ['json'], 'css' => ['css', 'style-attr'],
                'cssurl' => ['css', 'style-attr'], 'jsnum' => ['js'], 'gencss' => ['css']][$e];
            if (!in_array($kind, $ok, true)) throw new BuildError("$where: |$e can't be used in a $kind spot");
            switch ($e) {
                case 'rich': $s = Rich::html($v, (string)$arg, $this->refResolver, $slot['scope'], $ctx['dc'], $where); break;
                case 'js': $s = Encode::js($this->plain($v, $slot, $where), $arg); break;
                case 'json': $s = Encode::json($v); break;
                case 'jsnum':
                    if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) throw new BuildError("$where: not a number");
                    $s = (string)(0 + $v);
                    break;
                case 'gencss': // CSS the builder writes itself (never stored words)
                    if (strpos($path, 'derived.') !== 0 || !is_string($v) || preg_match('/[<\\\\]|\{\{|[^\x0a\x20-\x7e]/', $v)) throw new BuildError("$where: not builder CSS");
                    $s = $v;
                    break;
                case 'cssurl': // a site image used as a CSS background
                    $s = $this->plain($v, $slot, $where);
                    if (!preg_match('~^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:webp|jpe?g|png)$~', $s) || strpos($s, '..') !== false) throw new BuildError("$where: '$s' is not a site image");
                    break;
                default:
                    $s = $this->plain($v, $slot, $where);
                    if (!preg_match('/^[#0-9A-Za-z.,%() -]*$/', $s)) throw new BuildError("$where: '$s' is not a safe CSS value");
            }
        } else {
            $s = $this->plain($v, $slot, $where);
            switch ($kind) {
                case 'text': $s = Encode::text($s, $ctx['dc']); break;
                case 'rcdata': $s = Encode::rcdata($s); break;
                case 'attr': $s = Encode::attr($s, $ctx['dc']); break;
                case 'url':
                    // A URL value may be built from pieces (e.g. "tel:⟦phone|tel⟧"); only whole
                    // values are checked as links, pieces must be URL-safe on their own.
                    if ($ctx['whole']) $s = Encode::url($s);
                    elseif (preg_match('/[\s"\'<>\\\\`{}]/', $s)) throw new BuildError("$where: '$s' can't go inside a link");
                    else $s = str_replace('&', '&amp;', $s);
                    break;
                case 'style-attr':
                case 'css':
                    // plain numbers (positions, timings) are always safe in CSS
                    if (!preg_match('/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/', $s)) throw new BuildError("$where: style values need |css");
                    break;
                case 'js': case 'json': throw new BuildError("$where: values inside a script need |js or |json");
                default: throw new BuildError("$where: values can't go in a $kind spot");
            }
        }
        foreach ($post as $f) {
            if ($kind !== 'text') throw new BuildError("$where: |$f only works in text");
            $s = preg_replace('/@/', '<wbr>@', $s, 1) ?? $s;
        }
        return $s;
    }

    private function plain($v, array $slot, string $where): string
    {
        return Rich::plain($v, $this->refResolver, $slot['scope'], $where);
    }
}

/** Value filters applied before encoding. */
final class Filters
{
    public static function apply(string $f, ?string $arg, $v, callable $refs, array $scope, string $where)
    {
        switch ($f) {
            case 'upper': return Text::upper(Rich::plain($v, $refs, $scope, $where));
            case 'lower': return Text::lower(Rich::plain($v, $refs, $scope, $where));
            case 'cap': return Text::capFirst(Rich::plain($v, $refs, $scope, $where));
            case 'tel': return preg_replace('/\D/', '', Rich::plain($v, $refs, $scope, $where));
            case 'intl': // "+1-812-774-5049" for Google
                $d = preg_replace('/\D/', '', Rich::plain($v, $refs, $scope, $where));
                if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
                return strlen($d) === 10 ? '+1-' . substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6) : '+' . $d;
            case 'times': return (int)$v * (int)$arg;
            case 'plus': return (int)$v + (int)$arg;
            case 'max': return min((int)$v, (int)$arg);
            case 'pad': return str_pad((string)(int)$v, (int)($arg ?? 2), '0', STR_PAD_LEFT);
            case 'letter': return chr(65 + (int)$v + (int)($arg ?? 0));
            case 'or': return Template::truthy($v) ? $v : $arg;
            case 'pick': // a value per position, the last one repeating ("120,160,180")
                $opts = explode(',', (string)$arg);
                return $opts[min((int)$v, count($opts) - 1)];
            case 'count': // visible items in a list
                return is_array($v) ? count(array_filter($v, fn($it) => !(is_array($it) && !empty($it['hidden'])))) : 0;
            case 'ref': return $refs((string)$arg, $scope);
            case 'fmt': // "mailto:%s?subject=" — the value goes where %s is
                return str_replace('%s', Rich::plain($v, $refs, $scope, $where), (string)$arg);
            case 'bright':     // "Easier to read": light body text on dark backgrounds
            case 'brightdark': // dark body text on the orange bands
                return Brightness::alpha((string)$v, (string)$arg, $f === 'brightdark');
            case 'statefont': // px size for a state card name
                return Site::STATE_FONT[(string)$v] ?? 34;
            case 'statename':
                $code = (string)$v;
                if (!isset(Site::STATES[$code])) throw new BuildError("$where: unknown state '$code'");
                return Site::STATES[$code];
            default: throw new BuildError("$where: unknown filter |$f");
        }
    }
}

/**
 * "Easier to read" presets. Only text classified as body (paragraphs, descriptions, fine
 * print) follows them; its transparency moves toward fully opaque. Light text on the dark
 * pages and dark text on the orange bands have their own curves. Standard = today's look.
 */
final class Brightness
{
    public const PRESETS = ['standard' => [0.0, 0.0], 'brighter' => [0.35, 0.45], 'brightest' => [0.6, 0.75]];

    public static function alpha(string $preset, string $base, bool $dark): string
    {
        if (!isset(self::PRESETS[$preset])) throw new BuildError("Unknown brightness preset '$preset'");
        if ($preset === 'standard') return $base;
        $a = (float)$base;
        $k = self::PRESETS[$preset][$dark ? 1 : 0];
        $v = round($a + (1 - $a) * $k, 2);
        $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        return $s === '1' ? '1' : ltrim($s, '0');
    }
}

/** Case changes without mbstring (GoDaddy may run PHP without it). */
final class Text
{
    private const LOWER = 'àáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿ';
    private const UPPER = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞŸ';

    public static function upper(string $s): string
    {
        return strtr(strtoupper($s), self::pairs(self::LOWER, self::UPPER));
    }

    public static function lower(string $s): string
    {
        return strtr(strtolower($s), self::pairs(self::UPPER, self::LOWER));
    }

    public static function capFirst(string $s): string
    {
        if (!preg_match('/^(\P{L}*)(\X)(.*)$/su', $s, $m)) return $s;
        return $m[1] . self::upper($m[2]) . $m[3];
    }

    public static function length(string $s): int
    {
        return (int)preg_match_all('/\X/u', $s);
    }

    private static function pairs(string $from, string $to): array
    {
        preg_match_all('/./u', $from, $a);
        preg_match_all('/./u', $to, $b);
        return array_combine($a[0], $b[0]);
    }
}
