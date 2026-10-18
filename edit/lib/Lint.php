<?php
// Checks every built file against support.js's known quirks before anything goes live.
// Errors stop the build; warnings are reported.
declare(strict_types=1);

namespace ThinkSmart;

final class Lint
{
    /** Boolean attributes support.js would drop when written bare (write ="{{ true }}"). */
    private const BOOLEAN = ['required', 'disabled', 'checked', 'multiple', 'muted', 'loop', 'autoplay', 'hidden', 'open',
        'selected', 'readonly', 'novalidate', 'controls', 'default', 'formnovalidate', 'reversed', 'async', 'defer', 'autofocus', 'inert'];
    /** Bare attributes the design already uses and that work as they are. */
    private const BARE_OK = ['playsinline', 'data-dc-script', 'crossorigin'];
    /** support.js:365 */
    private const CAMEL_ATTR_RE = '/(\s)([a-z]+[A-Z][A-Za-z0-9]*)(\s*=)/';

    /**
     * @param string $file   output name (for messages)
     * @param string $out    built text
     * @param array  $trace  inserted values: [path, kind, start, end]
     * @param array  $allowedBindings  "{{ … }}" strings the templates themselves contain
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public static function check(string $file, string $out, array $trace, array $allowedBindings): array
    {
        $err = [];
        $warn = [];
        $isDc = str_ends_with($file, '.dc.html');
        if (preg_match('/[\x{E000}\x{E001}]|\x{27E6}|\x{27E7}/u', $out)) $err[] = 'template markers left in the output';
        if (strpos($out, 'window.__resources') !== false) $err[] = 'window.__resources appears (it would switch off support.js\'s second render pass)';

        foreach ($trace as [$path, $kind, $s, $e]) {
            $val = Html::decode(substr($out, $s, $e - $s));
            if (strpos($val, '{{') !== false || strpos($val, '}}') !== false) $err[] = "$path: stored text contains {{ or }}";
            if ($kind === 'text' && Input::camelEqualsRisk(' ' . $val)) $warn[] = "$path: text like \"x" . "Y=\" shows oddly for a moment before the page finishes loading";
        }

        if ($isDc) {
            $open = preg_match_all('/<x-dc(?:\s[^>]*)?>/', $out);
            $closeAt = strrpos($out, '</x-dc>');
            if ($open !== 1 || $closeAt === false) $err[] = 'needs exactly one <x-dc> … </x-dc>';
            $scriptAt = strpos($out, '<script type="text/x-dc" data-dc-script');
            if ($scriptAt !== false && $closeAt !== false && $closeAt > $scriptAt) $err[] = '</x-dc> appears inside the page script';
            $start = strpos($out, '<x-dc');
            $dc = ($start !== false && $closeAt !== false) ? substr($out, $start, $closeAt - $start) : '';
            self::checkDc($dc, $err);
            self::checkHelmets($dc, $err);
            // bindings: every {{ … }} must be one the templates wrote
            preg_match_all('/\{\{(.*?)\}\}/s', $out, $mm);
            foreach (array_unique($mm[0]) as $b) {
                if (!isset($allowedBindings[$b])) $err[] = "unknown binding $b";
            }
        }
        // links and media must stay inside the allowed schemes / the site's own files
        foreach (Html::tokenize($out) as $t) {
            if ($t['t'] !== 'tag') continue;
            foreach ($t['attrs'] as [$an, , $vs, $ve]) {
                $an = strtolower($an);
                if ($vs < 0 || !in_array($an, ['href', 'src', 'poster', 'action'], true)) continue;
                $v = Html::decode(substr($out, $vs, $ve - $vs));
                if (preg_match('/^\{\{[^}]+\}\}$/', $v)) continue;
                if ($t['name'] === 'link' || $t['name'] === 'script') continue;
                if (!Encode::urlAllowed($v) && !preg_match('~^https://fonts\.~', $v)) $err[] = "<{$t['name']} $an=\"$v\"> is not an allowed link";
            }
        }
        if (preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $out, $ld)) {
            foreach ($ld[1] as $j) if (json_decode($j) === null) $err[] = 'Google business info is not valid JSON';
        }
        return ['errors' => array_values(array_unique($err)), 'warnings' => array_values(array_unique($warn))];
    }

    /** Attribute names and raw text inside <x-dc>. */
    private static function checkDc(string $dc, array &$err): void
    {
        $toks = Html::tokenize($dc);
        foreach ($toks as $t) {
            if ($t['t'] !== 'tag') continue;
            foreach ($t['attrs'] as [$an, , $vs]) {
                // pass 1 reads a DOM that lowercased every attribute name, so camelCase props
                // only work for event handlers (support.js maps those back)
                if (preg_match('/[A-Z]/', $an) && !preg_match('/^on[A-Z][A-Za-z]+$/', $an)) $err[] = "<{$t['name']} $an=…>: camelCase attribute (fails in support.js's first pass)";
                if ($vs < 0 && in_array(strtolower($an), self::BOOLEAN, true)) $err[] = "<{$t['name']} $an>: bare boolean attribute is dropped; write $an=\"{{ true }}\"";
                if ($vs < 0 && !in_array(strtolower($an), self::BOOLEAN, true) && !in_array(strtolower($an), self::BARE_OK, true)) $err[] = "<{$t['name']} $an>: unexpected bare attribute";
            }
        }
        // support.js rewrites " camelWord=" anywhere in the raw template; it must only ever hit
        // real attribute names (event handlers), never text or attribute values
        if (preg_match_all(self::CAMEL_ATTR_RE, $dc, $mm, PREG_OFFSET_CAPTURE)) {
            $tagRanges = [];
            foreach ($toks as $t) if ($t['t'] === 'tag') $tagRanges[] = [$t['s'], $t['e'], $t['attrs']];
            foreach ($mm[2] as [$word, $at]) {
                $ok = false;
                foreach ($tagRanges as [$s, $e, $attrs]) {
                    if ($at < $s || $at >= $e) continue;
                    foreach ($attrs as [$an, $ns]) if ($ns === $at && $an === $word) $ok = true;
                }
                if (!$ok) $err[] = "\"$word=\" would be rewritten by support.js (camelCase word followed by =)";
            }
        }
    }

    /** In each <helmet>, the Google Fonts link must come after the <style> (WebKit otherwise holds styles back). */
    private static function checkHelmets(string $dc, array &$err): void
    {
        if (!preg_match_all('#<helmet(?:\s[^>]*)?>(.*?)</helmet>#s', $dc, $hh)) return;
        foreach ($hh[1] as $h) {
            $style = strpos($h, '<style');
            $font = strpos($h, 'fonts.googleapis.com/css2');
            if ($font !== false && $style !== false && $font < $style) $err[] = 'the Google Fonts link must come after <style> in <helmet>';
        }
    }

    /** "{{ … }}" strings written in the templates themselves. */
    public static function bindingsIn(string $dir): array
    {
        $out = [];
        foreach (array_merge(glob("$dir/*.html") ?: [], glob("$dir/partials/*.html") ?: []) as $f) {
            preg_match_all('/\{\{(.*?)\}\}/s', (string)file_get_contents($f), $mm);
            foreach ($mm[0] as $b) $out[$b] = true;
        }
        return $out;
    }
}
