<?php
// "Put it live": apply her changes to the newest live content, check them, build the whole
// site in the side folder, swap it in with the journal, then record History. Everything runs
// under one lock and keeps going if her phone locks mid-save (the request ignores the dropped
// connection); a half-finished publish is completed by the next request.
//
// Also: undo a change, bring back a deleted item, put the whole website back to a moment.
declare(strict_types=1);

namespace ThinkSmart;

final class Publisher
{
    private Config $cfg;
    private Paths $p;
    private array $schema;
    private People $people;
    public History $history;
    public LiveContent $live;
    public Drafts $drafts;
    public Explain $ex;
    private Validate $val;

    public function __construct(Config $cfg, People $people)
    {
        $this->cfg = $cfg;
        $this->p = $cfg->paths;
        $this->schema = Schema::load($cfg->paths);
        $this->people = $people;
        $this->history = new History($cfg);
        $this->live = new LiveContent($cfg, $this->schema);
        $this->drafts = new Drafts($cfg);
        $this->ex = new Explain($this->schema);
        $this->val = new Validate($this->schema);
    }

    /** Finish whatever an earlier request left half-done. */
    public function settle(): void
    {
        Lock::with($this->p, 'publish', fn() => $this->settleLocked());
    }

    private function settleLocked(): void
    {
        Journal::recover($this->p);
        if ($this->history->rev() === 0) {
            // the launch-day version, kept forever
            $this->history->add(['at' => Clock::now(), 'by' => null, 'kind' => 'launch', 'lines' => ['the website as it was on launch day'], 'keys' => []], $this->live->load(), []);
        }
        $j = Journal::read($this->p);
        if ($j && ($j['state'] ?? '') === 'done') {
            $pub = Json::read($j['build'] . '/publish.json');
            if (is_array($pub) && !$this->history->byPublishId((string)$pub['id'])) $this->record($pub);
        }
        $head = $this->history->head();
        if ($head && !empty($head['sensitive']) && empty($head['mailed'])) $this->alert($head);
    }

    public function status(string $person): ?array
    {
        return Json::read($this->p->private . '/status/' . preg_replace('/[^A-Za-z0-9]/', '', $person) . '.json');
    }

    private function setStatus(string $person, array $s): void
    {
        Json::write($this->p->private . '/status/' . preg_replace('/[^A-Za-z0-9]/', '', $person) . '.json', $s + ['at' => Clock::now()], false);
    }

    /** Apply operations to the live content and check the result, without building. */
    public function dryRun(array $ops, array $resolve, int $base): array
    {
        $before = $this->live->load();
        [$after, $conflicts] = Changes::apply($before, $ops, $resolve);
        $after = Changes::ensureIds($after, $this->live->lists());
        return [
            'before' => $before,
            'after' => $after,
            'conflicts' => $this->describeConflicts($conflicts, $base, $before),
            'problems' => $this->newProblems($before, $after),
            'warnings' => $this->val->warnings($before, $after, $ops),
            'also' => $this->ex->alsoChanges($before, $after),
            'lines' => $this->ex->lines($before, $after, $ops),
        ];
    }

    /** Problems the change brings in (a problem the live site already has never blocks her). */
    private function newProblems(array $before, array $after): array
    {
        $had = [];
        foreach ($this->val->problems($before) as $p) $had[$p['msg']] = true;
        return array_values(array_filter($this->val->problems($after), fn($p) => !isset($had[$p['msg']])));
    }

    public function describeConflicts(array $conflicts, int $base, array $live): array
    {
        $out = [];
        foreach ($conflicts as $c) {
            $sp = Changes::schemaPath($c['op']['path']);
            $e = $this->history->touching($c['key'], $base);
            $out[] = [
                'key' => $c['key'],
                'path' => $c['op']['path'],
                'why' => $c['why'],
                'label' => (string)($this->schema['fields'][$sp]['label'] ?? $sp),
                'theirs' => $c['why'] === 'changed' ? $this->ex->show($c['theirs'], $sp, $live) : '',
                'mine' => $this->ex->show($c['op']['value'] ?? null, $sp, $live),
                'by' => $e ? $e['by'] : null,
                'byName' => $e ? $this->people->name($e['by']) : 'Someone',
                'at' => $e ? $e['at'] : null,
            ];
        }
        return $out;
    }

    /**
     * @return array ok + entry, or conflicts / problems / error (nothing went live)
     */
    public function publish(string $person, array $ops, array $resolve, string $publishId, int $base, string $kind = 'publish', array $meta = []): array
    {
        if ($publishId === '') $publishId = Http::token();
        $this->setStatus($person, ['id' => $publishId, 'state' => 'working']);
        try {
            $r = Lock::with($this->p, 'publish', fn() => $this->publishLocked($person, $ops, $resolve, $publishId, $base, $kind, $meta));
        } catch (\Throwable $e) {
            Log::error('publish failed: ' . $e->getMessage());
            $this->setStatus($person, ['id' => $publishId, 'state' => 'failed']);
            return ['ok' => false, 'error' => "Something in this change stopped the website from building, so nothing went live. Your change is still here; try again, or undo the last thing you changed."];
        }
        if (!$r['ok']) $this->setStatus($person, ['id' => $publishId, 'state' => 'stopped']);
        return $r;
    }

    private function publishLocked(string $person, array $ops, array $resolve, string $publishId, int $base, string $kind, array $meta): array
    {
        $this->settleLocked();
        if ($e = $this->history->byPublishId($publishId)) return ['ok' => true, 'entry' => $e, 'again' => true, 'pages' => []];
        $before = $this->live->load();
        [$after, $conflicts] = Changes::apply($before, $ops, $resolve);
        if ($conflicts) return ['ok' => false, 'conflicts' => $this->describeConflicts($conflicts, $base, $before)];
        $after = Content::clean(Changes::ensureIds($after, $this->live->lists()));
        if (json_encode($after) === json_encode($before)) {
            $this->drafts->settle($person, $this->ex->keys($ops), $this->history->rev());
            $this->setStatus($person, ['id' => $publishId, 'state' => 'nothing']);
            return ['ok' => true, 'nothing' => true, 'pages' => []];
        }
        $problems = $this->newProblems($before, $after);
        if ($problems) return ['ok' => false, 'problems' => $problems];

        $build = new Build($this->p);
        $res = $build->build($after, $this->contractFiles($after));
        $changed = [];
        foreach ($res['files'] as $file => $m) if (Files::sha($this->p->webroot . '/' . $file) !== $m['sha']) $changed[] = $file;
        $pub = [
            'id' => $publishId, 'person' => $person, 'kind' => $kind, 'meta' => $meta, 'at' => Clock::now(),
            'lines' => $this->ex->lines($before, $after, $ops), 'keys' => $this->ex->keys($ops),
            'sensitive' => $this->ex->sensitive($ops), 'ops' => $ops, 'content' => $after, 'files' => $changed,
            'check' => $this->checkWords($ops, $after, $res['traces'], $changed),
        ];
        Json::write($res['dir'] . '/publish.json', $pub, false);
        Journal::publish($this->p, $res);
        $entry = $this->record($pub);
        $build->prune(5);
        if (random_int(1, 10) === 1) $this->tidy();
        return ['ok' => true, 'entry' => $entry, 'pages' => $pub['check']];
    }

    /** Save the content, add the History entry, settle drafts, email John. */
    private function record(array $pub): array
    {
        (new ContentStore($this->p))->save($pub['content']);
        $entry = $this->history->add([
            'at' => $pub['at'], 'by' => $pub['person'], 'kind' => $pub['kind'], 'meta' => $pub['meta'],
            'lines' => $pub['lines'], 'keys' => $pub['keys'], 'sensitive' => $pub['sensitive'],
            'publishId' => $pub['id'], 'files' => $pub['files'], 'check' => $pub['check'] ?? [],
        ], $pub['content'], $pub['ops']);
        $this->drafts->settle((string)$pub['person'], $pub['keys'], $entry['rev']);
        $this->setStatus((string)$pub['person'], ['id' => $pub['id'], 'state' => 'done', 'rev' => $entry['rev'], 'wentLive' => $pub['at']]);
        if ($entry['sensitive']) $this->alert($entry);
        return $entry;
    }

    /** "Muneerah changed the website" email to John, with an Undo button. */
    private function alert(array $entry): void
    {
        $this->history->update($entry['rev'], ['mailed' => true]);
        $who = $this->people->name($entry['by']);
        $to = $this->people->alertEmails();
        if (!$to) return;
        $tok = (new Tokens($this->cfg))->make('undo', ['rev' => $entry['rev']]);
        $lines = array_map(fn($l) => "• $who $l", $entry['lines']);
        $paras = array_merge(["$who changed the website (" . Clock::when($entry['at']) . '):'], [implode("\n", $lines)],
            ['If that looks right, there\'s nothing to do. If not, tap Undo: it puts these back the way they were.']);
        (new Mailer($this->cfg))->send($to, "$who changed the website", $paras, ['Undo this change', $this->cfg->siteUrl() . '/edit/undo?t=' . $tok], 'alert');
    }

    /** Contracts whose newest version isn't at its permanent address yet. */
    private function contractFiles(array $c): array
    {
        $out = [];
        foreach ($c['states'] ?? [] as $st) {
            $v = (string)($st['pdfV'] ?? '');
            $target = (string)($st['pdf'] ?? '');
            if ($target === '') continue;
            if ($v === '') {
                // no version picked in the editor: the contract that was online before the editor
                foreach (Contracts::versions($this->p, (string)($st['code'] ?? '')) as $ver) if (!empty($ver['first'])) $v = $ver['v'];
                if ($v === '') continue;
            }
            $src = Contracts::file($this->p, $v);
            if (!is_file($src)) throw new BuildError("Contract version $v is missing");
            if (Files::sha($this->p->webroot . '/' . $target) !== hash_file('sha256', $src)) $out[$target] = $src;
        }
        return $out;
    }

    /** A few words per changed page that the browser check expects to find once the page drew. */
    private function checkWords(array $ops, array $after, array $traces, array $changed): array
    {
        $want = [];
        foreach ($ops as $op) {
            if ($op['op'] !== 'set') continue;
            $sp = Changes::schemaPath($op['path']);
            if (!in_array($this->schema['fields'][$sp]['type'] ?? '', ['text', 'para', 'rich'], true)) continue;
            $dotted = Changes::dotted($after, $op['path']);
            $plain = $this->ex->show($op['value'], $sp, $after);
            if ($dotted === '' || Text::length($plain) < 4 || str_contains($plain, '…')) continue;
            $want[$dotted] = $plain;
        }
        $out = [];
        foreach ($changed as $file) {
            if (!str_ends_with($file, '.dc.html')) continue;
            $words = [];
            foreach ($traces[$file] ?? [] as $t) {
                if (($t[1] ?? '') !== 'text' || !isset($want[$t[4] ?? ''])) continue;
                $words[$want[$t[4]]] = true;
                if (count($words) >= 3) break;
            }
            $out[$file] = array_keys($words);
        }
        return $out;
    }

    // ---------------------------------------------------------------- undo and restore

    /** The operations that undo one History entry. */
    public function inverse(int $rev): ?array
    {
        $snap = $this->history->snapshot($rev);
        if (!$snap) return null;
        $prev = null;
        foreach (array_reverse($this->history->entries()) as $e) {
            if ($e['rev'] < $rev && ($s = $this->history->snapshot($e['rev']))) { $prev = $s['content']; break; }
        }
        $inv = [];
        foreach (array_reverse($snap['ops']) as $op) {
            if ($op['op'] === 'set') { $inv[] = ['op' => 'set', 'path' => $op['path'], 'value' => $op['old'] ?? null, 'old' => $op['value']]; continue; }
            $add = [];
            $gone = array_diff($op['old'] ?? [], $op['order']);
            if ($gone) {
                if ($prev === null) return null;
                foreach ((array)(Changes::get($prev, $op['path']) ?? []) as $it) if (in_array($it['_id'] ?? '', $gone, true)) $add[$it['_id']] = $it;
            }
            $inv[] = ['op' => 'list', 'path' => $op['path'], 'order' => $op['old'] ?? [], 'add' => $add, 'old' => $op['order']];
        }
        return $inv;
    }

    public function undo(string $person, int $rev, array $resolve, string $publishId, string $how = 'undo'): array
    {
        $e = $this->history->get($rev);
        if (!$e || $e['kind'] === 'launch') return ['ok' => false, 'error' => 'That change can\'t be undone.'];
        $inv = $this->inverse($rev);
        if ($inv === null) return ['ok' => false, 'error' => 'That change is too old to undo by itself. Use "Put the whole website back to this moment" instead.'];
        return $this->publish($person, $inv, $resolve, $publishId, $rev, $how, ['of' => $rev]);
    }

    /** Bring back an item a change deleted, where it was. */
    public function bringBack(string $person, int $rev, string $itemId, string $publishId): array
    {
        $snap = $this->history->snapshot($rev);
        if (!$snap) return ['ok' => false, 'error' => 'That change is too old to bring things back from.'];
        foreach ($snap['ops'] as $op) {
            if ($op['op'] !== 'list' || !in_array($itemId, $op['old'] ?? [], true) || in_array($itemId, $op['order'], true)) continue;
            $item = null;
            foreach (array_reverse($this->history->entries()) as $e) {
                if ($e['rev'] >= $rev) continue;
                $prev = $this->history->snapshot($e['rev']);
                if (!$prev) continue;
                foreach ((array)(Changes::get($prev['content'], $op['path']) ?? []) as $it) if (($it['_id'] ?? '') === $itemId) $item = $it;
                break;
            }
            if ($item === null) return ['ok' => false, 'error' => 'That item can\'t be found any more.'];
            $live = $this->live->load();
            // a question that was on Home comes back off Home if Home already shows 3
            if (Changes::schemaPath($op['path']) === 'faq.items' && !empty($item['home'])) {
                $starred = array_filter($live['faq']['items'] ?? [], fn($it) => empty($it['hidden']) && !empty($it['home']));
                if (count($starred) >= 3) $item['home'] = false;
            }
            $cur = array_map(fn($it) => $it['_id'], (array)(Changes::get($live, $op['path']) ?? []));
            if (in_array($itemId, $cur, true)) return ['ok' => false, 'error' => 'It\'s already back.'];
            $at = min(array_search($itemId, $op['old'], true), count($cur));
            $order = $cur;
            array_splice($order, $at, 0, [$itemId]);
            $ops = [['op' => 'list', 'path' => $op['path'], 'order' => $order, 'add' => [$itemId => $item], 'old' => $cur]];
            return $this->publish($person, $ops, [], $publishId, $this->history->rev(), 'bringback', ['of' => $rev]);
        }
        return ['ok' => false, 'error' => 'Nothing was deleted in that change.'];
    }

    /** Everyone else's changes a restore to $rev would remove. */
    public function laterChanges(int $rev, string $person): array
    {
        $out = [];
        foreach ($this->history->entries() as $e) {
            if ($e['rev'] <= $rev || $e['by'] === $person || $e['by'] === null) continue;
            $out[] = ['rev' => $e['rev'], 'at' => $e['at'], 'when' => Clock::when($e['at']), 'byName' => $this->people->name($e['by']), 'lines' => $e['lines']];
        }
        return $out;
    }

    /** Put the whole website back to a moment (logins, passwords and lead addresses aren't in it). */
    public function restore(string $person, int $rev, string $publishId, string $kind = 'restore'): array
    {
        $snap = $this->history->snapshot($rev);
        if (!$snap) return ['ok' => false, 'error' => 'That moment isn\'t kept any more.'];
        $live = $this->live->load();
        $ops = Changes::diff($live, $snap['content'], $this->live->lists());
        $all = [];
        foreach ($ops as $op) $all[Changes::key($op['path'])] = 'mine';
        return $this->publish($person, $ops, $all, $publishId, $this->history->rev(), $kind, ['to' => $rev]);
    }

    /** Old builds, unused photo uploads (older than a week) and old History. */
    public function tidy(): void
    {
        $this->history->prune();
        $used = $this->history->mediaInUse();
        History::collectMedia($this->live->load(), $used);
        foreach ($this->drafts->all() as $d) foreach ($d['ops'] ?? [] as $op) History::collectMedia($op, $used);
        foreach (glob($this->p->webroot . '/media/u/*') ?: [] as $f) {
            $rel = 'media/u/' . basename($f);
            if (!isset($used[$rel]) && is_file($f) && filemtime($f) < Clock::now() - 7 * 86400 && basename($f) !== '.htaccess') @unlink($f);
        }
    }
}
