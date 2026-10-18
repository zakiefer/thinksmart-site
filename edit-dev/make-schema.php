<?php
// Writes edit/schema.json: one entry per stored field (list items as "list[].field") with a
// plain label, a type, caps/marks/chips, fit limits and list limits, plus the editor screens.
// Labels come from the converter's hand-labeled spots (edit-dev/gate1/checkpoint/labels.json)
// and edit-dev/schema-overrides.php. Fails if a field has no label or a label uses a design name.
//   php edit-dev/make-schema.php
declare(strict_types=1);
require __DIR__ . '/../edit/lib/load.php';

use ThinkSmart\{Rich, Site, Template};

$repo = dirname(__DIR__);
$content = json_decode((string)file_get_contents("$repo/edit/seed/content.json"), true, 512, JSON_THROW_ON_ERROR);
$conv = json_decode((string)file_get_contents(__DIR__ . '/gate1/checkpoint/labels.json'), true, 512, JSON_THROW_ON_ERROR);
$over = require __DIR__ . '/schema-overrides.php';
$styles = require "$repo/edit/templates/rich-styles.php";

// Design names that must never reach her screens.
const BANNED = ['hero', 'cta', 'call to action', 'act 0', 'ticker', 'manifesto', 'exhibit', 'marquee', 'footer', 'header',
    'navbar', 'nav ', 'meta', 'og:', 'schema', 'json', 'html', 'template', 'component', 'slug', 'repeater', 'widget', 'partial', 'seo', 'lowball'];

/** Normalize a stored key: list indexes become [] when the path is a list in the templates. */
function norm(string $key, array $lists): string
{
    $seg = explode('.', $key);
    $out = [];
    foreach ($seg as $s) {
        $prefix = implode('.', $out);
        if (ctype_digit($s) && isset($lists[str_replace('[]', '', $prefix)])) { $out[count($out) - 1] .= '[]'; continue; }
        $out[] = $s;
    }
    return preg_replace('/\.\[\]/', '[]', implode('.', $out));
}

// 1. what the templates do with each path: lists, caps, rich styles
$lists = [];
$uses = [];
$scan = function (array $nodes, array $alias) use (&$scan, &$lists, &$uses, $repo) {
    foreach ($nodes as $n) {
        if ($n[0] === 'each') {
            $path = $n[1];
            $seg = explode('.', $path);
            if (isset($alias[$seg[0]])) $path = $alias[$seg[0]] . (count($seg) > 1 ? '[].' . implode('.', array_slice($seg, 1)) : '');
            if (strpos($path, 'derived.') !== 0) $lists[preg_replace('/\[\]\./', '.', $path)] = true;
            $a = $alias;
            $a[$n[2]] = $path;
            $scan($n[3], $a);
        } elseif ($n[0] === 'if') {
            $scan($n[2], $alias);
            $scan($n[3], $alias);
        } elseif ($n[0] === 'val') {
            $path = $n[1];
            $seg = explode('.', $path);
            if (isset($alias[$seg[0]])) $path = $alias[$seg[0]] . '[]' . (count($seg) > 1 ? '.' . implode('.', array_slice($seg, 1)) : '');
            foreach ($n[2] as [$f, $arg]) $uses[$path][$f === 'rich' ? "rich:$arg" : $f] = true;
            $uses[$path]['_'] = true;
        } elseif ($n[0] === 'part') {
            $scan(Template::parse((string)file_get_contents("$repo/edit/templates/partials/{$n[1]}.html")), $alias);
        }
    }
};
foreach (glob("$repo/edit/templates/*.html") as $f) $scan(Template::parse((string)file_get_contents($f)), []);
// derived lists read these stored lists
foreach (['faq.items', 'home.reviews.items', 'home.marquee.phrases', 'states', 'site.social'] as $l) $lists[$l] = true;

// 2. stored leaves
$isRuns = function ($v): bool {
    if (!is_array($v) || !array_is_list($v) || $v === []) return false;
    foreach ($v as $r) {
        if (is_string($r)) continue;
        if (!is_array($r) || array_diff(array_keys($r), ['ref', 'hl', 'b', 'call', 'br', 'cap']) !== []) return false;
    }
    return true;
};
$leaves = [];
$walk = function ($v, string $key) use (&$walk, &$leaves, $isRuns, $lists, $over) {
    $n = norm($key, $lists);
    if (isset($over['fields'][$n]) && in_array($over['fields'][$n]['type'], ['address', 'person', 'guarantee', 'number-quote', 'notice'], true) && $n !== 'notice') {
        $leaves[$n][] = $v;
        return;
    }
    if (is_array($v) && !$isRuns($v) && $v !== []) {
        foreach ($v as $k => $x) $walk($x, $key === '' ? (string)$k : "$key.$k");
        return;
    }
    $leaves[$n][] = $v;
};
$walk($content, '');

// 3. labels
$labels = [];
foreach ($conv as $k => $l) $labels[norm($k, $lists)] = $l;

$fields = [];
$problems = [];
foreach ($leaves as $path => $examples) {
    $ov = $over['fields'][$path] ?? [];
    $label = $ov['label'] ?? ($labels[$path]['label'] ?? null);
    $u = $uses[$path] ?? [];
    $first = $examples[0];
    $type = $ov['type'] ?? null;
    if ($type === null) {
        if (is_bool($first)) $type = 'tick';
        elseif (is_int($first) || is_float($first)) $type = 'number';
        elseif (preg_match('/\.(photo|poster)$/', $path)) $type = 'photo';
        elseif (preg_match('/\.video$/', $path)) $type = 'video';
        elseif (preg_match('/photoAlt$/', $path)) $type = 'alt';
        else {
            $rich = array_values(array_filter(array_keys($u), fn($f) => str_starts_with($f, 'rich:')));
            $type = $rich ? 'rich' : (preg_match('/(text|intro|answer|\.a\[\]\.text|caption)$/', $path) && strlen(Rich::plain($first, fn() => 'x', [], $path)) > 80 ? 'para' : 'text');
        }
    }
    if ($label === null) {
        // internal values and parts of a composite field
        if (preg_match('/(^|\.)(hidden|on|home|which|type|buttonTo|highlight|bigNumber|licensed|value|plus|percent|code)$/', $path) || str_contains($path, 'notice.')) {
            $label = ['on' => 'Shown', 'home' => 'Show on Home', 'highlight' => 'Orange', 'value' => 'Number', 'plus' => 'Starts with +', 'percent' => 'Ends with %', 'code' => 'State', 'hidden' => 'Hidden for now'][preg_replace('/^.*\./', '', $path)] ?? null;
        }
        if (str_ends_with($path, '.caption') && str_starts_with($path, 'numbers.')) $label = 'Caption';
        if (preg_match('/^(services\.items\[\]\.(photo|photoAlt)|home\.damage\.cards\[\]\.\w+|services\.coverage\.tiles\[\]\.\w+|home\.steps\[\]\.\w+|claim\.steps\[\]\.\w+|about\.\w+\[\]\.\w+)$/', $path)) $label = $labels[$path]['label'] ?? null;
    }
    if ($label === null) { $problems[] = "no label: $path"; continue; }
    $low = strtolower($label);
    foreach (BANNED as $b) if (str_contains($low, $b)) $problems[] = "label for $path uses a design name ('$b'): $label";
    $f = ['label' => $label, 'type' => $type];
    if (isset($u['upper']) || !empty($ov['caps'])) $f['caps'] = true;
    foreach (array_keys($u) as $use) {
        if (str_starts_with($use, 'rich:')) {
            $st = substr($use, 5);
            $f['style'] = $st;
            $f['marks'] = array_values(array_filter(array_keys($styles[$st]), fn($k) => !str_starts_with($k, 'ref:')));
        }
    }
    // chips allowed: the ones it holds today, plus any for a rich style's links
    $chips = [];
    foreach ($examples as $ex) if ($isRuns($ex)) foreach ($ex as $r) if (is_array($r) && isset($r['ref'])) $chips[$r['ref']] = true;
    if ($chips) $f['chips'] = array_keys($chips);
    // fit meter: "Fits" up to the first number, "Too long" past the second
    if (in_array($type, ['text', 'para', 'rich', 'alt'], true)) {
        $len = max(array_map(fn($ex) => \ThinkSmart\Text::length(Rich::plain($ex, fn($n) => Site::ref($n, $GLOBALS['content']), [], $path)), $examples));
        $f['fit'] = $ov['fit'] ?? ($len < 60 ? [(int)round($len * 1.25 + 4), (int)round($len * 1.6 + 8)] : [(int)round($len * 1.4), (int)round($len * 2.2)]);
    }
    foreach (['help', 'options', 'parts', 'optional', 'required', 'hard', 'min', 'max', 'add', 'rule', 'default', 'locked'] as $k) if (isset($ov[$k])) $f[$k] = $ov[$k];
    $fields[$path] = $f;
}
// list entries themselves
foreach ($over['fields'] as $path => $ov) {
    if (in_array($ov['type'], ['list', 'checklist'], true)) $fields[$path] = $ov + ($fields[$path] ?? []);
}
foreach ($fields as $path => $f) if (in_array($f['type'], ['list', 'checklist'], true) && !isset($f['min'], $f['max'])) $problems[] = "list without limits: $path";
if ($problems) { fwrite(STDERR, implode("\n", $problems) . "\n"); exit(1); }

ksort($fields);
$schema = [
    'version' => 1,
    'note' => 'Generated by edit-dev/make-schema.php. Paths use [] for list items. "fit" = [fits up to, too long after] characters.',
    'chips' => Site::REFS,
    'screens' => $over['screens'],
    'fields' => $fields,
];
file_put_contents("$repo/edit/schema.json", json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("schema: %d fields (%d lists), %d screens\n", count($fields), count(array_filter($fields, fn($f) => in_array($f['type'], ['list', 'checklist'], true))), count($over['screens']));
