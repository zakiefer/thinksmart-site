<?php
// Raw-text HTML tokenizer. It never re-serializes anything: every token carries the
// byte offsets of the source it came from, so callers can splice the original text.
declare(strict_types=1);

namespace ThinkSmart;

final class Html
{
    /** Elements whose content is raw text (no tags, no entities). */
    private const RAW = ['script' => true, 'style' => true];
    /** Elements whose content is text with entities but no tags. */
    private const RCDATA = ['title' => true, 'textarea' => true];

    /**
     * Tokens, in source order:
     *   ['t' => 'doctype'|'comment', 's', 'e']
     *   ['t' => 'tag', 's', 'e', 'name', 'attrs' => [[name, nameStart, valueStart, valueEnd, quote]], 'self' => bool]
     *   ['t' => 'end', 's', 'e', 'name']
     *   ['t' => 'text', 's', 'e', 'in' => parent raw element name or '']   (text between tags)
     * Offsets are byte offsets; 'e' is exclusive. A bare attribute has valueStart = valueEnd = -1.
     */
    public static function tokenize(string $src): array
    {
        $out = [];
        $n = strlen($src);
        $i = 0;
        $textStart = 0;
        while ($i < $n) {
            $lt = strpos($src, '<', $i);
            if ($lt === false) break;
            $next = $lt + 1 < $n ? $src[$lt + 1] : '';
            if ($next === '!') {
                if (substr($src, $lt, 4) === '<!--') {
                    $end = strpos($src, '-->', $lt + 4);
                    $end = $end === false ? $n : $end + 3;
                    self::text($out, $src, $textStart, $lt);
                    $out[] = ['t' => 'comment', 's' => $lt, 'e' => $end];
                } else {
                    $end = strpos($src, '>', $lt);
                    $end = $end === false ? $n : $end + 1;
                    self::text($out, $src, $textStart, $lt);
                    $out[] = ['t' => 'doctype', 's' => $lt, 'e' => $end];
                }
                $i = $textStart = $end;
                continue;
            }
            if ($next === '/' && $lt + 2 < $n && ctype_alpha_safe($src[$lt + 2])) {
                $m = [];
                preg_match('/\G<\/([A-Za-z][A-Za-z0-9:-]*)\s*>/', $src, $m, 0, $lt);
                if ($m) {
                    self::text($out, $src, $textStart, $lt);
                    $out[] = ['t' => 'end', 's' => $lt, 'e' => $lt + strlen($m[0]), 'name' => strtolower($m[1])];
                    $i = $textStart = $lt + strlen($m[0]);
                    continue;
                }
            }
            if ($next !== '' && ctype_alpha_safe($next)) {
                $tag = self::startTag($src, $lt);
                if ($tag !== null) {
                    self::text($out, $src, $textStart, $lt);
                    $out[] = $tag;
                    $i = $textStart = $tag['e'];
                    $name = $tag['name'];
                    if ((isset(self::RAW[$name]) || isset(self::RCDATA[$name])) && !$tag['self']) {
                        $close = self::findClose($src, $name, $i);
                        if ($close > $i) $out[] = ['t' => 'text', 's' => $i, 'e' => $close, 'in' => $name];
                        $i = $textStart = $close;
                    }
                    continue;
                }
            }
            $i = $lt + 1; // a stray '<' is just text
        }
        self::text($out, $src, $textStart, $n);
        return $out;
    }

    private static function text(array &$out, string $src, int $s, int $e): void
    {
        if ($e > $s) $out[] = ['t' => 'text', 's' => $s, 'e' => $e, 'in' => ''];
    }

    private static function findClose(string $src, string $name, int $from): int
    {
        if (preg_match('/<\/' . $name . '[\s>\/]/i', $src, $m, PREG_OFFSET_CAPTURE, $from)) return $m[0][1];
        return strlen($src);
    }

    private static function startTag(string $src, int $lt): ?array
    {
        $n = strlen($src);
        if (!preg_match('/\G<([A-Za-z][A-Za-z0-9:-]*)/', $src, $m, 0, $lt)) return null;
        $name = strtolower($m[1]);
        $i = $lt + strlen($m[0]);
        $attrs = [];
        $self = false;
        while ($i < $n) {
            $c = $src[$i];
            if ($c === '>') {
                return ['t' => 'tag', 's' => $lt, 'e' => $i + 1, 'name' => $name, 'attrs' => $attrs, 'self' => $self];
            }
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f") { $i++; continue; }
            if ($c === '/') { $self = ($i + 1 < $n && $src[$i + 1] === '>'); $i++; continue; }
            $self = false;
            $ns = $i;
            while ($i < $n && strpos(" \t\n\r\f/>=", $src[$i]) === false) $i++;
            $aname = substr($src, $ns, $i - $ns);
            $j = $i;
            while ($j < $n && strpos(" \t\n\r\f", $src[$j]) !== false) $j++;
            if ($j < $n && $src[$j] === '=') {
                $j++;
                while ($j < $n && strpos(" \t\n\r\f", $src[$j]) !== false) $j++;
                $q = $j < $n ? $src[$j] : '';
                if ($q === '"' || $q === "'") {
                    $close = strpos($src, $q, $j + 1);
                    if ($close === false) return null;
                    $attrs[] = [$aname, $ns, $j + 1, $close, $q];
                    $i = $close + 1;
                } else {
                    $vs = $j;
                    while ($j < $n && strpos(" \t\n\r\f>", $src[$j]) === false) $j++;
                    $attrs[] = [$aname, $ns, $vs, $j, ''];
                    $i = $j;
                }
            } else {
                $attrs[] = [$aname, $ns, -1, -1, ''];
            }
        }
        return null;
    }

    /** Decoded text of an HTML text run or attribute value (entities resolved, as a browser would). */
    public static function decode(string $raw): string
    {
        return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The attribute list of a tag token as name => decoded value (first wins, names lowercased). */
    public static function attrs(string $src, array $tag): array
    {
        $out = [];
        foreach ($tag['attrs'] as [$name, , $vs, $ve]) {
            $k = strtolower($name);
            if (array_key_exists($k, $out)) continue;
            $out[$k] = $vs < 0 ? null : self::decode(substr($src, $vs, $ve - $vs));
        }
        return $out;
    }
}

/** ctype_alpha without relying on the ctype extension (GoDaddy may run PHP with it off). */
function ctype_alpha_safe(string $c): bool
{
    $o = ord($c);
    return ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122);
}
