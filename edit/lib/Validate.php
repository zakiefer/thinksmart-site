<?php
// Checks before anything goes live. Problems stop a change (a wrong phone number, a list past
// its limit, a review without its "real customer" tick); warnings only ask her to look again.
// Each one names the spot, so the editor can jump straight to the box.
declare(strict_types=1);

namespace ThinkSmart;

final class Validate
{
    private array $schema;
    private Explain $ex;

    public function __construct(array $schema)
    {
        $this->schema = $schema;
        $this->ex = new Explain($schema);
    }

    /**
     * Every place a schema path occurs: [change path, value, visible].
     * @return list<array{0: array, 1: mixed, 2: bool}>
     */
    public static function instances(array $content, string $sp): array
    {
        $segs = [];
        foreach (explode('.', str_replace('[]', '[].', $sp)) as $s) if ($s !== '') $segs[] = $s;
        // "items[]" then "q" -> walk lists by item
        $out = [];
        $walk = function ($node, int $i, array $path, bool $vis) use (&$walk, &$out, $segs) {
            if ($i === count($segs)) { $out[] = [$path, $node, $vis]; return; }
            $seg = $segs[$i];
            if (str_ends_with($seg, '[]')) {
                $key = substr($seg, 0, -2);
                $list = $key === '' ? $node : ($node[$key] ?? null);
                if (!is_array($list)) return;
                $base = $key === '' ? $path : array_merge($path, [$key]);
                foreach ($list as $it) {
                    if (!is_array($it)) continue;
                    $walk($it, $i + 1, array_merge($base, [['id' => (string)($it['_id'] ?? '')]]), $vis && empty($it['hidden']));
                }
                return;
            }
            if (!is_array($node) || !array_key_exists($seg, $node)) { if ($i === count($segs) - 1) $out[] = [array_merge($path, [$seg]), null, $vis]; return; }
            $walk($node[$seg], $i + 1, array_merge($path, [$seg]), $vis);
        };
        $walk($content, 0, [], true);
        return $out;
    }

    private function p(array $path, string $msg): array
    {
        return ['key' => Changes::key($path), 'path' => $path, 'msg' => $msg];
    }

    /** Things that must be fixed before this can go live. */
    public function problems(array $c): array
    {
        $out = [];
        foreach (Content::problems($c) as $m) $out[] = ['key' => '', 'path' => [], 'msg' => self::plainProblem($m)];
        foreach ($this->schema['fields'] as $sp => $f) {
            $type = $f['type'] ?? 'text';
            $label = (string)($f['label'] ?? $sp);
            foreach (self::instances($c, $sp) as [$path, $v, $vis]) {
                if (in_array($type, ['list', 'checklist'], true)) {
                    $items = is_array($v) ? $v : [];
                    $shown = array_filter($items, fn($it) => is_array($it) && empty($it['hidden']) && ($type !== 'checklist' || !empty($it['on'])));
                    if (isset($f['max']) && count($items) > $f['max']) $out[] = $this->p($path, "$label: this spot holds up to {$f['max']}. Hide or delete one first.");
                    if ($vis && isset($f['min']) && count($shown) < $f['min']) $out[] = $this->p($path, "$label: needs at least {$f['min']} showing.");
                    continue;
                }
                if (!$vis) continue;
                // a photo spot can't be empty, and new list items need their words
                if ($type === 'photo' && empty($f['optional']) && empty($f['editorOnly']) && ($v === null || $v === '')) {
                    $leaf = (string)end($path);
                    $video = Changes::get($c, array_merge(array_slice($path, 0, -1), ['video']));
                    // a still picture matters only with a video; a tile showing its video needs no photo
                    $needed = $leaf === 'poster' ? !empty($video) : !($leaf === 'photo' && !empty($video));
                    if ($needed) $out[] = $this->p($path, $this->itemLabel($sp, $c, $path) . ': add a photo.');
                    continue;
                }
                if ($sp === 'contact.rows[].text' && (Changes::get($c, array_merge(array_slice($path, 0, -1), ['type'])) ?? 'text') !== 'text') continue;
                if (in_array($type, ['text', 'para', 'rich', 'name'], true) && empty($f['optional']) && str_contains($sp, '[]') && ($v === '' || $v === null || $v === [])) {
                    $out[] = $this->p($path, $this->itemLabel($sp, $c, $path) . ': this box is empty.');
                    continue;
                }
                if (!empty($f['required']) && $type === 'tick' && $v !== true) {
                    $out[] = $this->p($path, $this->requiredMsg($sp, $c, $path));
                    continue;
                }
                if (isset($f['hard']) && (is_string($v) || is_array($v)) && $v !== []) {
                    $plain = $this->ex->show($v, $sp, $c);
                    if (Text::length($plain) > (int)$f['hard']) $out[] = $this->p($path, "$label: too long for its spot (up to {$f['hard']} letters).");
                }
                if ($type === 'datetime' && is_string($v) && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v)) $out[] = $this->p($path, "$label: pick a date and time.");
                if ($type === 'money' && !(is_int($v) && $v >= 0 && $v < 100000)) $out[] = $this->p($path, "$label: type a whole number of dollars.");
                if ($type === 'slug' && !(is_string($v) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $v))) $out[] = $this->p($path, "$label is missing.");
            }
        }
        // reviews: words, name and the tick
        foreach (self::instances($c, 'home.reviews.items[]') as [$path, $r, $vis]) {
            if (!$vis || !is_array($r)) continue;
            $who = trim((string)($r['name'] ?? '')) !== '' ? Derive::shortName((string)$r['name']) : 'a customer';
            if (trim((string)($r['text'] ?? '')) === '') $out[] = $this->p(array_merge($path, ['text']), "The review from $who needs their words.");
            if (trim((string)($r['name'] ?? '')) === '') $out[] = $this->p(array_merge($path, ['name']), 'A review needs the customer\'s name.');
        }
        // FAQ: exactly 3 on Home
        $starred = array_filter($c['faq']['items'] ?? [], fn($it) => is_array($it) && empty($it['hidden']) && !empty($it['home']));
        if (count($starred) !== 3) $out[] = ['key' => 'faq.items', 'path' => ['faq', 'items'], 'msg' => 'Home shows exactly 3 questions. Star 3 of them (now ' . count($starred) . ').'];
        // states: each once
        $seen = [];
        foreach ($c['states'] ?? [] as $st) {
            $code = (string)($st['code'] ?? '');
            if ($code === '') { $out[] = ['key' => 'states', 'path' => ['states'], 'msg' => 'Pick the state for each card in "States we serve".']; continue; }
            if (isset($seen[$code])) $out[] = ['key' => 'states', 'path' => ['states'], 'msg' => (Site::STATES[$code] ?? $code) . ' is in the list twice.'];
            $seen[$code] = true;
        }
        // service link addresses: unique
        $ids = [];
        foreach ($c['services']['items'] ?? [] as $s) {
            $id = (string)($s['id'] ?? '');
            if ($id !== '' && isset($ids[$id])) $out[] = ['key' => 'services.items', 'path' => ['services', 'items'], 'msg' => 'Two services have the same link address.'];
            $ids[$id] = true;
        }
        // keep the order of messages stable and drop repeats
        $uniq = [];
        foreach ($out as $p) $uniq[$p['key'] . '|' . $p['msg']] = $p;
        return array_values($uniq);
    }

    /** "Questions & answers, new item: question" — which box, in her words. */
    private function itemLabel(string $sp, array $c, array $path): string
    {
        return (string)($this->schema['fields'][$sp]['label'] ?? $sp);
    }

    private function requiredMsg(string $sp, array $c, array $path): string
    {
        $item = Changes::get($c, array_slice($path, 0, -1));
        if ($sp === 'states[].licensed') {
            $name = Site::STATES[$item['code'] ?? ''] ?? 'this state';
            return 'Tick "' . ($c['site']['adjuster']['name'] ?? 'The adjuster') . " is licensed here\" for $name, or remove it.";
        }
        if ($sp === 'home.reviews.items[].real') {
            $who = trim((string)($item['name'] ?? '')) !== '' ? Derive::shortName((string)$item['name']) : 'this customer';
            return "Tick \"Real customer, copied word for word\" for the review from $who.";
        }
        return ($this->schema['fields'][$sp]['label'] ?? $sp) . ' needs a tick.';
    }

    private static function plainProblem(string $m): string
    {
        $map = [
            'The phone setting needs a 10-digit number' => 'The main phone number needs 10 digits.',
            'The phone2 setting needs a 10-digit number' => 'The second phone number needs 10 digits (or leave it empty).',
            'The email setting is not an email address' => 'The email shown on the site doesn\'t look like an email address.',
        ];
        return $map[$m] ?? $m;
    }

    /** Things to look at again; they never stop a change (a storm notice is never held up). */
    public function warnings(array $before, array $after, array $ops): array
    {
        $out = [];
        $touched = array_map(fn($op) => Changes::key($op['path']), $ops);
        foreach ($ops as $op) {
            $sp = Changes::schemaPath($op['path']);
            $f = $this->schema['fields'][$sp] ?? [];
            $type = $f['type'] ?? '';
            $label = (string)($f['label'] ?? $sp);
            if ($op['op'] === 'set' && isset($f['fit'][1]) && in_array($type, ['text', 'para', 'rich', 'alt'], true)) {
                $len = Text::length($this->ex->show($op['value'], $sp, $after));
                if ($len > $f['fit'][1]) $out[] = $this->p($op['path'], "$label is long, so it may not fit its spot. Check it in the preview.");
            }
            if ($type === 'photo' && $op['op'] === 'set') {
                $alt = $op['path'];
                $alt[count($alt) - 1] = end($op['path']) . 'Alt';
                if (isset($this->schema['fields'][Changes::schemaPath($alt)]) && !in_array(Changes::key($alt), $touched, true)) {
                    $out[] = $this->p($alt, 'New photo: does "What\'s in this photo?" still describe it?');
                }
            }
            if ($sp === 'states[].esign' && is_string($op['value']) && $op['value'] !== '' && ($w = self::esignWarning($op['value'])) !== null) $out[] = $this->p($op['path'], $w);
        }
        // a hidden number still quoted in an answer
        foreach (['underpaid', 'higher', 'overturned'] as $k) {
            $n = $after['numbers'][$k] ?? null;
            if (!is_array($n) || empty($n['hidden'])) continue;
            $txt = (!empty($n['plus']) ? '+' : '') . ($n['value'] ?? '') . (!empty($n['percent']) ? '%' : '');
            foreach ($after['faq']['items'] ?? [] as $it) {
                foreach ($it['a'] ?? [] as $a) {
                    $plain = $this->ex->show($a['text'] ?? '', 'faq.items[].a[].text', $after);
                    if ($txt !== '' && str_contains($plain, $txt)) { $out[] = $this->p(['faq', 'items', ['id' => $it['_id'] ?? ''], 'q'], "The number $txt is hidden, but an answer still quotes it: “" . Explain::cut($this->ex->show($it['q'] ?? '', 'faq.items[].q', $after), 50) . '”.'); break 2; }
                }
            }
        }
        return $out;
    }

    /** Adobe's public web forms look like …/public/esignWidget?wid=… */
    public static function esignWarning(string $url): ?string
    {
        if (!preg_match('~^https://~', $url)) return 'The e-sign link must start with https://';
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $adobe = (bool)preg_match('/(^|\.)(adobe\.com|adobesign\.com|echosign\.com)$/', $host);
        if ($adobe && preg_match('~/public/esignWidget\?wid=[A-Za-z0-9*_.-]+~', $url)) return null;
        if ($adobe) return 'This link looks like one person\'s agreement, not the public web form. Ask John for the web form link.';
        return 'This isn\'t an Adobe web form link. Test it to be sure it opens the blank agreement.';
    }
}
