<?php
// Encoders for every place a value can land in a built page, plus the input rules that
// keep stored text safe for support.js's two render passes.
//
// Why these rules (support.js):
// - Both passes interpolate "{{ … }}" in DECODED text and attribute values, so HTML entities
//   do not neutralize "{{". Stored text never contains "{{" or "}}" (see Input::clean).
// - Pass 2 runs CAMEL_ATTR_RE (support.js:365) over the raw template text, which would rewrite
//   text like " iPhone=" into an attribute name. Every "=" in text and in non-URL attribute
//   values inside <x-dc> is written as "&#61;" so the raw text never matches.
declare(strict_types=1);

namespace ThinkSmart;

final class Encode
{
    /** Text node. */
    public static function text(string $s, bool $inDc): string
    {
        $s = str_replace(['&', '<', '>', "\u{00A0}"], ['&amp;', '&lt;', '&gt;', '&nbsp;'], $s);
        return $inDc ? str_replace('=', '&#61;', $s) : $s;
    }

    /** Double-quoted attribute value (not a URL). */
    public static function attr(string $s, bool $inDc): string
    {
        $s = str_replace(['&', '"', '<', '>', "\u{00A0}"], ['&amp;', '&quot;', '&lt;', '&gt;', '&nbsp;'], $s);
        return $inDc ? str_replace('=', '&#61;', $s) : $s;
    }

    /**
     * URL attribute value. Only https:, tel:, mailto:, in-page anchors and the site's own
     * relative paths are allowed; a URL has no spaces, so its "=" can't trip CAMEL_ATTR_RE.
     */
    public static function url(string $s): string
    {
        if (!self::urlAllowed($s)) throw new BuildError('Link not allowed: ' . $s);
        return str_replace(['&', '"', '<', '>'], ['&amp;', '&quot;', '%3C', '%3E'], $s);
    }

    public static function urlAllowed(string $s): bool
    {
        if ($s === '' || preg_match('/[\s"\'<>\\\\`{}\x00-\x1f\x7f]/u', $s)) return false;
        if (preg_match('~^https://[a-z0-9.-]+\.[a-z]{2,}(?::\d+)?(?:[/?#]\S*)?$~i', $s)) return true;
        if (preg_match('/^tel:\+?[0-9]{3,15}$/', $s)) return true;
        if (preg_match('/^mailto:[^@\s?]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}(?:\?[A-Za-z0-9%._~&=+-]*)?$/', $s)) return true;
        if (preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $s)) return true;
        // the site's own files: pages, media, contracts (no "..", no scheme)
        if (preg_match('~^(?:\./|/)?(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:html|pdf|webp|jpe?g|png|mp4|xml)(?:\?v=[A-Za-z0-9]+)?(?:#[A-Za-z][A-Za-z0-9_-]*)?$~', $s)
            && strpos($s, '..') === false) return true;
        return $s === '/';
    }

    /** RCDATA (<title>): entities are decoded but no tags. */
    public static function rcdata(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    /**
     * A complete JavaScript string literal for a <script data-dc-script> block. The quote
     * character follows the site's own style (single quotes unless the text has an apostrophe
     * and no double quote). "<" and ">" are escaped so the text can never close the script
     * element, open an HTML comment, or be mistaken for <x-dc> by support.js's raw-text parser.
     */
    public static function js(string $s, ?string $quote = null): string
    {
        $q = (strpos($s, "'") !== false && strpos($s, '"') === false) ? '"' : "'";
        if ($quote === 'dq') $q = '"';
        elseif ($quote === 'sq') $q = "'";
        $map = ['\\' => '\\\\', $q => '\\' . $q, '<' => '\\x3C', '>' => '\\x3E', "\n" => '\\n', "\r" => '\\r',
            "\u{2028}" => '\\u2028', "\u{2029}" => '\\u2029'];
        $out = strtr($s, $map);
        $out = preg_replace_callback('/[\x00-\x1f\x7f]/', fn($m) => sprintf('\\x%02X', ord($m[0])), $out);
        return $q . $out . $q;
    }

    /** A JSON document inside <script type="application/ld+json"> (re-emitted whole). */
    public static function json($v): string
    {
        $s = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        if ($s === false) throw new BuildError('Could not encode JSON: ' . json_last_error_msg());
        return $s;
    }

    /** XML text (sitemap). */
    public static function xml(string $s): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $s);
    }
}

/** Input rules applied to every stored string before it is saved or built. */
final class Input
{
    /**
     * - "{{" and "}}" are broken with a zero-width space: support.js would otherwise treat the
     *   text as a template binding in both passes (entities don't help, it checks decoded text).
     * - Private-use characters are removed (the builder uses them internally as placeholders).
     * - Control characters other than newline/tab are removed; line endings become "\n".
     */
    public static function clean(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|[\x{E000}-\x{F8FF}]|[\x{27E6}\x{27E7}]/u', '', $s) ?? '';
        while (strpos($s, '{{') !== false) $s = str_replace('{{', "{\u{200B}{", $s);
        while (strpos($s, '}}') !== false) $s = str_replace('}}', "}\u{200B}}", $s);
        return $s;
    }

    /** True when the text would be shown oddly by support.js's first render pass (pass 2 is safe). */
    public static function camelEqualsRisk(string $s): bool
    {
        return (bool)preg_match('/(?:^|\s)[a-z]+[A-Z][A-Za-z0-9]*\s*=/', $s);
    }
}

final class BuildError extends \RuntimeException
{
}
