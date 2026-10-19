<?php
// Plain words for changes: the History sentences ("changed Main phone number from … to …
// (all pages)"), item names for lists, which changes are sensitive (emailed to John), and
// the "this will also change…" lines for things the website works out by itself.
declare(strict_types=1);

namespace ThinkSmart;

final class Explain
{
    /** Spots John is emailed about when they change. */
    public const SENSITIVE = ['site.phone', 'site.phone2', 'site.email', 'site.social', 'site.social[].platform', 'site.social[].url',
        'states[].esign', 'states[].pdf', 'states[].pdfV'];

    private array $schema;

    public function __construct(array $schema)
    {
        $this->schema = $schema;
    }

    public function field(string $schemaPath): array
    {
        return $this->schema['fields'][$schemaPath] ?? ['label' => $schemaPath, 'type' => 'text'];
    }

    public static function cut(string $s, int $n = 70): string
    {
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        if (Text::length($s) <= $n) return $s;
        preg_match('/^.{0,' . ($n - 1) . '}/us', $s, $m);
        return rtrim($m[0]) . '…';
    }

    /** The words a value shows, for sentences and before/after views. */
    public function show($v, string $sp, array $content): string
    {
        $f = $this->field($sp);
        $type = $f['type'] ?? 'text';
        if ($v === null || $v === '' || $v === []) return '';
        switch ($type) {
            case 'tick': case 'switch': case 'star': return $v ? 'on' : 'off';
            case 'choice': return (string)($f['options'][$v] ?? (is_string($v) ? $v : json_encode($v)));
            case 'photo': return 'a photo';
            case 'video': return 'a video';
            case 'pdf': return 'a contract';
            case 'state': return Site::STATES[$v] ?? (string)$v;
            case 'states': return is_array($v) ? Site::joinList(array_map(fn($c) => Site::STATES[$c] ?? $c, $v), ' and ') : '';
            case 'money': return '$' . $v;
            case 'address':
                return is_array($v) ? trim(($v['street'] ?? '') . ', ' . ($v['city'] ?? '') . ', ' . ($v['region'] ?? '') . ' ' . ($v['zip'] ?? ''), ', ') : (string)$v;
            case 'person': return is_array($v) ? trim(($v['name'] ?? '') . ', ' . ($v['title'] ?? ''), ', ') : (string)$v;
            case 'guarantee': return is_array($v) ? self::cut((string)($v['short'] ?? '')) : (string)$v;
            case 'number-quote':
                return is_array($v) ? (!empty($v['plus']) ? '+' : '') . ($v['value'] ?? '') . (!empty($v['percent']) ? '%' : '') : (string)$v;
            case 'datetime': return $v ? Notice::wordsFor((string)$v) : '';
            case 'list': case 'checklist': return count((array)$v) . ' items';
        }
        if (is_array($v) && !array_is_list($v)) return '';
        try {
            return self::cut(Rich::plain($v, fn($n) => Site::ref($n, $content), ['root' => $content], $sp));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** "a question", "a review" — from the list's "Add a question" button words. */
    public function kind(string $listSp): string
    {
        $add = (string)($this->field($listSp)['add'] ?? '');
        $k = (string)preg_replace('/^Add\s*/i', '', $add);
        return $k === '' ? 'an item' : $k;
    }

    /** A short name for a list item: its question, name, title or words. */
    public function title($item, string $listSp, array $content): string
    {
        if (!is_array($item)) return self::cut((string)$item, 40);
        if (isset($item['code']) && isset(Site::STATES[$item['code']])) return Site::STATES[$item['code']];
        foreach (['q', 'name', 'title', 'text', 'label', 'platform', 'tag', 'headline'] as $k) {
            if (!isset($item[$k])) continue;
            $t = $this->show($item[$k], "{$listSp}[].$k", $content);
            if ($t !== '' && $t !== 'on' && $t !== 'off') return self::cut($t, 44);
        }
        if (isset($item['a'][0]['text'])) return self::cut($this->show($item['a'][0]['text'], 'faq.items[].a[].text', $content), 44);
        return 'one item';
    }

    private static function allPages(string $sp): bool
    {
        return (bool)preg_match('/^(site\.(phone|phone2|email|address|businessName|social|seal|brightness|callback|price|guarantee|adjuster)|chrome\.)/', $sp);
    }

    /** One sentence per change, without who did it ("You" / "John" is added when shown). */
    public function lines(array $before, array $after, array $ops): array
    {
        $out = [];
        foreach ($ops as $op) {
            $sp = Changes::schemaPath($op['path']);
            $f = $this->field($sp);
            $label = (string)($f['label'] ?? $sp);
            if ($op['op'] === 'list') {
                $oldItems = [];
                foreach ((array)(Changes::get($before, $op['path']) ?? []) as $it) if (is_array($it)) $oldItems[$it['_id'] ?? ''] = $it;
                foreach ($op['add'] ?? [] as $it) $out[] = 'added ' . $this->kind($sp) . ' “' . $this->title($it, $sp, $after) . '”';
                $gone = array_diff($op['old'] ?? [], $op['order']);
                foreach ($gone as $id) if (isset($oldItems[$id])) $out[] = 'deleted ' . $this->kind($sp) . ' “' . $this->title($oldItems[$id], $sp, $before) . '”';
                $kept = array_values(array_filter($op['order'], fn($id) => in_array($id, $op['old'] ?? [], true)));
                $wasOrder = array_values(array_filter($op['old'] ?? [], fn($id) => in_array($id, $kept, true)));
                if ($kept !== $wasOrder) $out[] = 'changed the order of ' . lcfirst($label);
                continue;
            }
            $leaf = end($op['path']);
            $item = null;
            $listSp = null;
            foreach ($op['path'] as $i => $seg) {
                if (is_array($seg)) {
                    $item = Changes::get($after, array_slice($op['path'], 0, $i + 1)) ?? Changes::get($before, array_slice($op['path'], 0, $i + 1));
                    $listSp = Changes::schemaPath(array_slice($op['path'], 0, $i));
                }
            }
            $where = $item !== null ? ' (' . $this->title($item, (string)$listSp, $after) . ')' : '';
            if ($leaf === 'hidden') { $out[] = ($op['value'] ? 'hid ' : 'showed again ') . $this->kind((string)$listSp) . ' “' . $this->title($item, (string)$listSp, $after) . '”'; continue; }
            if ($leaf === 'home' && $sp === 'faq.items[].home') { $out[] = ($op['value'] ? 'put “' : 'took “') . $this->title($item, 'faq.items', $after) . ($op['value'] ? '” on Home' : '” off Home'); continue; }
            $type = $f['type'] ?? 'text';
            $old = $this->show($op['old'] ?? null, $sp, $before);
            $new = $this->show($op['value'], $sp, $after);
            $all = self::allPages($sp) ? ' (all pages)' : '';
            if (in_array($type, ['tick', 'switch', 'star'], true)) { $out[] = 'turned ' . ($op['value'] ? 'on ' : 'off ') . lcfirst($label) . $where . $all; continue; }
            if (in_array($type, ['photo', 'video'], true)) { $out[] = 'changed ' . lcfirst($label) . $where . $all; continue; }
            if ($sp === 'states[].pdfV' || $type === 'pdf') { $out[] = 'put in a new contract' . $where; continue; }
            if ($old === '' && $new !== '') $out[] = 'added ' . lcfirst($label) . $where . ': “' . $new . '”' . $all;
            elseif ($new === '' && $old !== '') $out[] = 'cleared ' . lcfirst($label) . $where . $all;
            elseif ($old === $new) $out[] = 'changed ' . lcfirst($label) . $where . $all;
            else $out[] = 'changed ' . lcfirst($label) . $where . ' from “' . $old . '” to “' . $new . '”' . $all;
        }
        return array_values(array_unique($out));
    }

    public function keys(array $ops): array
    {
        return array_values(array_unique(array_map(fn($op) => Changes::key($op['path']), $ops)));
    }

    public function sensitive(array $ops): bool
    {
        foreach ($ops as $op) {
            $sp = Changes::schemaPath($op['path']);
            if (in_array($sp, self::SENSITIVE, true)) return true;
            if ($op['op'] === 'list' && $sp === 'states') {
                foreach ($op['add'] ?? [] as $it) if (!empty($it['esign']) || !empty($it['pdf'])) return true;
            }
        }
        return false;
    }

    /**
     * Things the website works out by itself, as visitors read them (for "this will also
     * change…" before/after lines).
     */
    public function derived(array $c): array
    {
        $out = [];
        try {
            $out['Top of Home: small line above the headline'] = !empty($c['home']['top']['kickerAuto']) ? Derive::homeTopLine($c) : $this->show($c['home']['top']['kicker'] ?? '', 'home.top.kicker', $c);
            $out['Bottom of every page: license line'] = !empty($c['chrome']['footer']['licenseAuto']) ? Derive::licenseLine($c) : $this->show($c['chrome']['footer']['license'] ?? '', 'chrome.footer.license', $c);
            $out['File a Claim: "Sign for your state" cards'] = implode(', ', array_map(fn($s) => $s['name'] . (empty($s['esign']) ? ' (call to sign)' : ''), Site::states($c)));
            $out['File a Claim: "Need a hard copy?" buttons'] = implode(', ', array_map(fn($s) => $s['name'], array_filter(Site::states($c), fn($s) => !empty($s['pdf']))));
            foreach (['claim.byPhone.text' => '"By phone" card', 'seo.home.title' => 'Home page: name on Google', 'seo.home.description' => 'Home page: short description on Google'] as $sp => $l) {
                $v = Changes::get($c, explode('.', $sp));
                if ($v !== null) $out[$l] = Rich::plain($v, fn($n) => Site::ref($n, $c), ['root' => $c], $sp);
            }
            foreach ($c['contact']['rows'] ?? [] as $row) {
                if (($row['type'] ?? '') === 'states' && empty($row['hidden'])) $out['Contact: "' . Rich::plain($row['label'] ?? '', fn($n) => Site::ref($n, $c), ['root' => $c], 'row') . '" row'] = Site::ref('stateNames', $c);
            }
        } catch (\Throwable $e) {
            // a half-finished state (no state picked yet) just shows fewer lines
        }
        return $out;
    }

    /** @return list<array{what:string, before:string, after:string}> */
    public function alsoChanges(array $before, array $after): array
    {
        $a = $this->derived($before);
        $b = $this->derived($after);
        $out = [];
        foreach ($b as $k => $v) if (($a[$k] ?? null) !== $v) $out[] = ['what' => $k, 'before' => $a[$k] ?? '', 'after' => $v];
        return $out;
    }
}
