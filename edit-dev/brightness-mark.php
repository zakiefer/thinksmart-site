<?php
// Marks body-text colors in the templates so they follow the "Easier to read" setting.
// Rules: paragraphs, descriptions, card texts, captions, form messages and mono fine print
// follow it; kickers, labels, tags, links, buttons, the ticker, top bar, phone menu, chat and
// the bottom of every page stay locked. Dark text on the orange bands has its own curve.
// Prints every decision; run once (it refuses to run twice).
declare(strict_types=1);
require __DIR__ . '/../edit/lib/Html.php';
require __DIR__ . '/lib/Dom.php';
$dir = __DIR__ . '/../edit/templates';
const FINE = ['⟦numbers.source|upper⟧', '⟦home.damage.hint|upper⟧', '⟦home.statement.hint|upper⟧', '⟦x.finePrint|upper⟧',
    '⟦forms.claim.submitNote|upper⟧', '⟦forms.contact.replies|upper⟧', '⟦home.guarantee.finePrint|upper⟧', '⟦services.cta.note|upper⟧'];
$apply = in_array('--apply', $argv, true);
$total = ['body' => 0, 'locked' => 0];
foreach (array_merge(glob("$dir/*.html"), glob("$dir/partials/*.html")) as $f) {
    $src = file_get_contents($f);
    if (strpos($src, '|bright') !== false) { fwrite(STDERR, basename($f) . " already marked\n"); continue; }
    $doc = new ThinkSmartDev\Doc($src);
    $edits = [];
    $walk = function ($n, $ctx) use (&$walk, &$edits, $src, $f, &$total) {
        foreach ($n->children() as $c) {
            $sub = $ctx;
            if (in_array($c->tag, ['header', 'footer', 'button', 'label'], true)) $sub[] = $c->tag;
            if (in_array($c->attrs['data-ref'] ?? '', ['mobileMenu', 'chatPanel'], true)) $sub[] = 'menu';
            $st = (string)($c->attrs['style'] ?? '');
            if (preg_match('/(?<![-\w])color:\s*rgba\((235,229,216|11,14,19),\s*(\.\d+)\)/', $st, $m, PREG_OFFSET_CAPTURE)) {
                $dark = $m[1][0] === '11,14,19';
                $mono = strpos($st, 'IBM Plex Mono') !== false;
                $text = trim(preg_replace('/\s+/', ' ', $c->text()));
                $body = !$sub && $c->tag !== 'a' && ($mono ? in_array($text, FINE, true) : in_array($c->tag, ['p', 'li', 'div', 'span'], true));
                $total[$body ? 'body' : 'locked']++;
                printf("%-6s %-14s %-4s %-5s %s\n", $body ? 'BODY' : 'locked', basename($f), $c->tag, ($dark ? 'd' : 'l') . $m[2][0], mb_substr($text, 0, 70));
                if ($body) {
                    // offset of the alpha inside the start tag's style attribute
                    $tagSrc = substr($src, $c->s0, $c->e0 - $c->s0);
                    $at = strpos($tagSrc, $m[0][0]);
                    $alphaAt = $c->s0 + $at + strlen($m[0][0]) - strlen($m[2][0]) - 1;
                    $edits[] = [$alphaAt, strlen($m[2][0]), '⟦site.brightness|' . ($dark ? 'brightdark' : 'bright') . ':' . $m[2][0] . '⟧'];
                }
            }
            $walk($c, $sub);
        }
    };
    $walk($doc->root, []);
    usort($edits, fn($a, $b) => $b[0] <=> $a[0]);
    foreach ($edits as [$at, $len, $rep]) {
        if (substr($src, $at, $len) !== substr($rep, strrpos($rep, ':') + 1, -strlen('⟧'))) throw new RuntimeException("offset check failed in $f");
        $src = substr($src, 0, $at) . $rep . substr($src, $at + $len);
    }
    if ($apply) file_put_contents($f, $src);
}
fprintf(STDERR, "body %d, locked %d%s\n", $total['body'], $total['locked'], $apply ? ' (applied)' : ' (dry run)');
