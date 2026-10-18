<?php
// Words with marks. A stored text is either a plain string or a list of runs:
//   "plain words"
//   {"hl": "orange words"}        Highlight button
//   {"b": "bold words"}           Bold button
//   {"call": "words"}             words that dial the main phone
//   {"br": true}                  a fixed line break (headlines keep today's breaks)
//   {"ref": "phone"}              a locked chip showing a setting (see Site::REFS)
// How marks look is decided per spot by a named style (templates/rich-styles.php), so the
// same "Bold" can be a plain <strong> in one place and a colored one in another.
declare(strict_types=1);

namespace ThinkSmart;

final class Rich
{
    private static ?array $styles = null;
    private static string $stylesFile = '';

    public static function useStyles(string $file): void
    {
        self::$stylesFile = $file;
        self::$styles = null;
    }

    public static function styles(): array
    {
        if (self::$styles === null) {
            if (self::$stylesFile === '' || !is_file(self::$stylesFile)) throw new BuildError('Rich text styles not set');
            self::$styles = require self::$stylesFile;
        }
        return self::$styles;
    }

    /** @return list<string|array> */
    public static function runs($v, string $where): array
    {
        if (is_string($v)) return [$v];
        if (is_int($v) || is_float($v)) return [(string)$v];
        if ($v === null) throw new BuildError("$where: no text stored here");
        if (!is_array($v) || !array_is_list($v)) throw new BuildError("$where: expected text");
        return $v;
    }

    /** The words as visitors read them, with chips filled in and marks dropped. */
    public static function plain($v, callable $refs, array $scope, string $where): string
    {
        $out = '';
        foreach (self::runs($v, $where) as $r) {
            if (is_string($r)) { $out .= $r; continue; }
            if (!is_array($r)) throw new BuildError("$where: bad text piece");
            if (isset($r['ref'])) { $val = $refs((string)$r['ref'], $scope); $out .= !empty($r['cap']) ? Text::capFirst($val) : $val; }
            elseif (isset($r['hl'])) $out .= (string)$r['hl'];
            elseif (isset($r['b'])) $out .= (string)$r['b'];
            elseif (isset($r['call'])) $out .= (string)$r['call'];
            elseif (!empty($r['br'])) $out .= ' ';
            else throw new BuildError("$where: unknown text piece " . json_encode($r));
        }
        return $out;
    }

    /** HTML for a text spot, using the spot's style for marks and chips. */
    public static function html($v, string $styleName, callable $refs, array $scope, bool $inDc, string $where): string
    {
        $styles = self::styles();
        if (!isset($styles[$styleName])) throw new BuildError("$where: unknown text style '$styleName'");
        $st = $styles[$styleName];
        $out = '';
        foreach (self::runs($v, $where) as $r) {
            if (is_string($r)) { $out .= Encode::text($r, $inDc); continue; }
            if (isset($r['ref'])) {
                $name = (string)$r['ref'];
                $val = $refs($name, $scope);
                if (!empty($r['cap'])) $val = Text::capFirst($val); // a chip starting a sentence
                $tpl = $st['ref:' . $name] ?? null;
                foreach (['hl', 'b'] as $mark) {
                    if (($r[$mark] ?? null) === true) { // a chip shown highlighted or bold
                        if (!isset($st[$mark])) throw new BuildError("$where: this spot has no '$mark' mark");
                        $tpl = $st[$mark];
                    }
                }
                $out .= $tpl === null ? Encode::text($val, $inDc) : self::fill($tpl, $val, $val, $inDc, $where);
                continue;
            }
            foreach (['hl', 'b', 'call'] as $mark) {
                if (isset($r[$mark])) {
                    if (!isset($st[$mark])) throw new BuildError("$where: this spot has no '$mark' mark");
                    $link = $mark === 'call' ? $refs('phone', $scope) : '';
                    $out .= self::fill($st[$mark], (string)$r[$mark], $link, $inDc, $where);
                    continue 2;
                }
            }
            if (!empty($r['br'])) {
                if (!isset($st['br'])) throw new BuildError("$where: this spot can't have a line break");
                $out .= $st['br'];
                continue;
            }
            throw new BuildError("$where: unknown text piece " . json_encode($r));
        }
        return $out;
    }

    /** {} = the words, {tel} = digits of the link value, {mail} = the link value as an address. */
    private static function fill(string $tpl, string $text, string $link, bool $inDc, string $where): string
    {
        $tel = preg_replace('/\D/', '', $link);
        $repl = ['{}' => Encode::text($text, $inDc), '{tel}' => $tel, '{mail}' => Encode::attr($link, false)];
        if (strpos($tpl, '{mail}') !== false && !Encode::urlAllowed('mailto:' . $link)) throw new BuildError("$where: '$link' is not an email address");
        if (strpos($tpl, '{tel}') !== false && !Encode::urlAllowed('tel:' . $tel)) throw new BuildError("$where: '$link' is not a phone number");
        return strtr($tpl, $repl);
    }

    /**
     * Converter side: read today's HTML for a spot back into runs, given the spot's style and
     * the settings that may appear inside it as chips (ref name => value as shown).
     */
    public static function parse(string $raw, string $styleName, array $chips, string $where): array
    {
        $st = $styleName === '' ? [] : (self::styles()[$styleName] ?? null);
        if ($st === null) throw new BuildError("$where: unknown text style '$styleName'");
        // one regex per mark/chip template
        $pats = [];
        foreach ($st as $k => $tpl) {
            $q = preg_quote($tpl, '~');
            $q = str_replace([preg_quote('{}', '~'), preg_quote('{tel}', '~'), preg_quote('{mail}', '~')], ['(?<t>[^<]*)', '(?:[0-9]+)', '(?:[^"]*)'], $q);
            $pats[$k] = '~\G' . $q . '~u';
        }
        $runs = [];
        $i = 0;
        $n = strlen($raw);
        $text = '';
        $flush = function () use (&$text, &$runs, $chips) {
            if ($text === '') return;
            $plain = Html::decode($text);
            $text = '';
            foreach (self::splitChips($plain, $chips) as $piece) $runs[] = $piece;
        };
        while ($i < $n) {
            if ($raw[$i] === '<') {
                $hit = false;
                foreach ($pats as $k => $re) {
                    if (!preg_match($re, $raw, $m, 0, $i)) continue;
                    $flush();
                    if ($k === 'br') $runs[] = ['br' => true];
                    elseif (strpos($k, 'ref:') === 0) $runs[] = ['ref' => substr($k, 4)];
                    else {
                        $words = Html::decode($m['t']);
                        $chip = array_search($words, $chips, true);
                        // a whole highlighted/bold chip, e.g. the guarantee in the FAQ answer
                        $runs[] = $chip !== false && $k !== 'call' ? ['ref' => $chip, $k => true] : [$k => $words];
                    }
                    $i += strlen($m[0]);
                    $hit = true;
                    break;
                }
                if (!$hit) throw new BuildError("$where: markup not covered by style '$styleName': " . substr($raw, $i, 60));
                continue;
            }
            $lt = strpos($raw, '<', $i);
            $end = $lt === false ? $n : $lt;
            $text .= substr($raw, $i, $end - $i);
            $i = $end;
        }
        $flush();
        // merge adjacent strings
        $out = [];
        foreach ($runs as $r) {
            if (is_string($r) && $out && is_string($out[count($out) - 1])) $out[count($out) - 1] .= $r;
            else $out[] = $r;
        }
        return count($out) === 1 && is_string($out[0]) ? [$out[0]] : $out;
    }

    /** Split plain text around chip values ("Call 812-774-5049" => "Call ", {ref: phone}). */
    private static function splitChips(string $s, array $chips): array
    {
        if (!$chips || $s === '') return [$s];
        // longest values first so "812-774-5049" can't be found inside a longer chip
        uksort($chips, fn($a, $b) => strlen($chips[$b]) <=> strlen($chips[$a]));
        $alts = [];
        foreach ($chips as $name => $val) $alts[] = '(?<' . preg_replace('/\W/', '_', $name) . '>' . preg_quote($val, '/') . ')';
        $re = '/' . implode('|', $alts) . '/u';
        $out = [];
        $pos = 0;
        if (!preg_match_all($re, $s, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [$s];
        foreach ($ms as $m) {
            [$val, $at] = $m[0];
            if ($at > $pos) $out[] = substr($s, $pos, $at - $pos);
            foreach ($chips as $name => $cv) {
                $g = preg_replace('/\W/', '_', $name);
                if (isset($m[$g]) && $m[$g][1] === $at && $m[$g][0] === $val) { $out[] = ['ref' => $name]; break; }
            }
            $pos = $at + strlen($val);
        }
        if ($pos < strlen($s)) $out[] = substr($s, $pos);
        return $out;
    }
}
