<?php
// The editor's JSON API (/edit/api/<name>), for a signed-in person. Every POST carries the
// session's CSRF token in a header and must come from the editor's own page; saves arrive as
// a "data" file part (see Http::data).
declare(strict_types=1);

namespace ThinkSmart;

final class Api
{
    private Config $cfg;
    private People $people;
    private Sessions $sessions;
    private array $session;
    private array $me;
    private Publisher $pub;

    public function __construct(Config $cfg, People $people, Sessions $sessions, array $session)
    {
        $this->cfg = $cfg;
        $this->people = $people;
        $this->sessions = $sessions;
        $this->session = $session;
        $this->me = (array)$people->find($session['person']);
        $this->pub = new Publisher($cfg, $people);
    }

    private const POST = ['draft', 'check', 'publish', 'undo', 'bringback', 'restore', 'autoundo', 'photo', 'contract', 'people-add',
        'people-update', 'people-remove', 'people-restore', 'people-reset', 'people-rescue', 'password', 'words-add', 'words-remove', 'prefs', 'signout'];

    public function handle(string $name): void
    {
        try {
            if (in_array($name, self::POST, true)) {
                if (Http::method() !== 'POST') { Http::json(['error' => 'Use POST'], 405); return; }
                if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/')) {
                    Http::json(['error' => 'That file is bigger than this hosting accepts (' . ini_get('post_max_size') . '). Ask John for a smaller copy.'], 413);
                    return;
                }
                $sent = (string)($_SERVER['HTTP_X_TS_CSRF'] ?? '');
                if (!Http::sameOrigin() || $sent === '' || !hash_equals((string)$this->session['csrf'], $sent)) {
                    Http::json(['error' => 'This page was open too long. Reload it and try again.', 'reload' => true], 403);
                    return;
                }
                ignore_user_abort(true); // her phone may lock mid-save: finish the job anyway
                @set_time_limit(120);
            }
            $this->pub->settle();
            $m = 'do' . str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));
            if (!method_exists($this, $m)) { Http::json(['error' => 'Unknown request'], 404); return; }
            $this->$m(Http::method() === 'POST' ? Http::data() : $_GET);
        } catch (UserError $e) {
            Http::json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error("api $name: " . get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
            Http::json(['error' => 'Something went wrong on the website\'s side. Nothing was changed. Try again in a minute.'], 500);
        }
    }

    private function me(): string
    {
        return (string)$this->me['id'];
    }

    private function names(): array
    {
        $out = [];
        foreach ($this->people->all() as $p) $out[$p['id']] = $p['name'];
        return $out;
    }

    private function entryOut(array $e): array
    {
        return [
            'rev' => $e['rev'], 'at' => $e['at'], 'when' => Clock::when($e['at']), 'by' => $e['by'],
            'kind' => $e['kind'], 'lines' => $e['lines'], 'meta' => $e['meta'] ?? [], 'files' => $e['files'] ?? [],
        ];
    }

    private function liveOut(): array
    {
        $h = $this->pub->history->head();
        return ['rev' => $this->pub->history->rev(), 'content' => $this->pub->live->load(), 'at' => $h['at'] ?? null, 'by' => $h['by'] ?? null];
    }

    private function draftOut(): array
    {
        $d = $this->pub->drafts->get($this->me());
        $live = $this->pub->live->load();
        $conflicts = [];
        if ($d['ops'] && $d['base'] < $this->pub->history->rev()) {
            [, $c] = Changes::apply($live, $d['ops']);
            $conflicts = $this->pub->describeConflicts($c, (int)$d['base'], $live);
        }
        return $d + ['conflicts' => $conflicts];
    }

    private function words(): array
    {
        return Json::read($this->cfg->paths->private . '/words.json', []);
    }

    private function prefs(): array
    {
        return Json::read($this->cfg->paths->private . '/prefs/' . $this->me() . '.json', []);
    }

    // ---------------------------------------------------------------- reading

    private function doBoot(array $q): void
    {
        $status = $this->pub->status($this->me());
        $live = $this->liveOut();
        Http::json([
            'me' => ['id' => $this->me(), 'name' => $this->me['name'], 'username' => $this->me['username'], 'email' => $this->me['email'], 'alerts' => !empty($this->me['alerts'])],
            'names' => $this->names(),
            'csrf' => $this->session['csrf'],
            'schema' => Schema::load($this->cfg->paths),
            'live' => $live,
            'draft' => $this->draftOut(),
            'status' => $status && ($status['at'] ?? 0) > Clock::now() - 86400 ? $status + ['when' => Clock::when((int)($status['wentLive'] ?? $status['at']))] : null,
            'words' => array_keys($this->words()),
            'prefs' => $this->prefs(),
            'pages' => Site::PAGES,
            'states' => Site::STATES,
            'callback' => Site::CALLBACK,
            'now' => Clock::now(),
            'site' => $this->cfg->siteUrl(),
            'leadsLast' => null,
        ]);
    }

    private function doState(array $q): void
    {
        $h = $this->pub->history->head();
        $status = $this->pub->status($this->me());
        Http::json([
            'rev' => $this->pub->history->rev(), 'at' => $h['at'] ?? null, 'by' => $h['by'] ?? null, 'when' => $h ? Clock::when($h['at']) : '',
            'status' => $status ? $status + ['when' => Clock::when((int)($status['wentLive'] ?? $status['at']))] : null, 'now' => Clock::now(),
        ]);
    }

    private function doLive(array $q): void
    {
        Http::json(['live' => $this->liveOut(), 'draft' => $this->draftOut()]);
    }

    private function doHistory(array $q): void
    {
        $out = [];
        foreach (array_reverse($this->pub->history->entries()) as $e) $out[] = $this->entryOut($e);
        Http::json(['entries' => $out, 'names' => $this->names()]);
    }

    /** One entry in detail: each change's before and after, and items that can be brought back. */
    private function doHistoryEntry(array $q): void
    {
        $rev = (int)($q['rev'] ?? 0);
        $e = $this->pub->history->get($rev);
        $snap = $e ? $this->pub->history->snapshot($rev) : null;
        if (!$e) throw new UserError('That change isn\'t in History any more.');
        $prev = null;
        foreach (array_reverse($this->pub->history->entries()) as $x) {
            if ($x['rev'] < $rev && ($s = $this->pub->history->snapshot($x['rev']))) { $prev = $s['content']; break; }
        }
        $after = $snap['content'] ?? [];
        $ex = $this->pub->ex;
        $rows = [];
        foreach ($snap['ops'] ?? [] as $op) {
            $sp = Changes::schemaPath($op['path']);
            $label = (string)($ex->field($sp)['label'] ?? $sp);
            if ($op['op'] === 'set') {
                $rows[] = ['label' => $label, 'before' => $ex->show($op['old'] ?? null, $sp, $prev ?? $after), 'after' => $ex->show($op['value'], $sp, $after), 'type' => $ex->field($sp)['type'] ?? 'text',
                    'beforeRaw' => in_array($ex->field($sp)['type'] ?? '', ['photo'], true) ? $op['old'] : null, 'afterRaw' => in_array($ex->field($sp)['type'] ?? '', ['photo'], true) ? $op['value'] : null];
                continue;
            }
            $gone = array_diff($op['old'] ?? [], $op['order']);
            foreach ($gone as $id) {
                $item = null;
                foreach ((array)(Changes::get($prev ?? [], $op['path']) ?? []) as $it) if (($it['_id'] ?? '') === $id) $item = $it;
                $rows[] = ['label' => $label, 'deleted' => $item ? $ex->title($item, $sp, $prev) : 'an item', 'item' => $id];
            }
            foreach ($op['add'] ?? [] as $it) $rows[] = ['label' => $label, 'added' => $ex->title($it, $sp, $after)];
        }
        Http::json(['entry' => $this->entryOut($e), 'rows' => $rows, 'canUndo' => $e['kind'] !== 'launch' && $this->pub->inverse($rev) !== null]);
    }

    private function doRestorePreview(array $q): void
    {
        $rev = (int)($q['rev'] ?? 0);
        Http::json(['later' => $this->pub->laterChanges($rev, $this->me())]);
    }

    // ---------------------------------------------------------------- saving

    private function ops(array $d): array
    {
        return Changes::sanitize(is_array($d['ops'] ?? null) ? $d['ops'] : [], Schema::load($this->cfg->paths));
    }

    private function resolve(array $d): array
    {
        $out = [];
        foreach ((array)($d['resolve'] ?? []) as $k => $v) if (is_string($k) && in_array($v, ['mine', 'theirs'], true)) $out[$k] = $v;
        return $out;
    }

    /** Drafts save every few seconds. */
    private function doDraft(array $d): void
    {
        $ops = $this->ops($d);
        $saved = $this->pub->drafts->put($this->me(), (int)($d['base'] ?? 0), $ops);
        Http::json(['ok' => true, 'saved' => $saved['saved'], 'count' => count($ops)]);
    }

    /** "Check your changes": problems, warnings, conflicts and what else will change. */
    private function doCheck(array $d): void
    {
        $r = $this->pub->dryRun($this->ops($d), $this->resolve($d), (int)($d['base'] ?? 0));
        unset($r['before'], $r['after']);
        Http::json($r);
    }

    private function doPublish(array $d): void
    {
        $id = preg_match('/^[A-Za-z0-9_-]{8,64}$/', (string)($d['id'] ?? '')) ? (string)$d['id'] : '';
        $r = $this->pub->publish($this->me(), $this->ops($d), $this->resolve($d), $id, (int)($d['base'] ?? 0));
        $this->answer($r);
    }

    private function answer(array $r): void
    {
        if (!empty($r['entry'])) $r['entry'] = $this->entryOut($r['entry']);
        $r['live'] = $this->liveOut();
        $r['draft'] = $this->draftOut();
        Http::json($r, $r['ok'] ? 200 : (isset($r['error']) ? 500 : 409));
    }

    private function doUndo(array $d): void
    {
        $this->answer($this->pub->undo($this->me(), (int)($d['rev'] ?? 0), $this->resolve($d), (string)($d['id'] ?? '')));
    }

    private function doBringback(array $d): void
    {
        $this->answer($this->pub->bringBack($this->me(), (int)($d['rev'] ?? 0), (string)($d['item'] ?? ''), (string)($d['id'] ?? '')));
    }

    private function doRestore(array $d): void
    {
        $this->answer($this->pub->restore($this->me(), (int)($d['rev'] ?? 0), (string)($d['id'] ?? '')));
    }

    /** The browser check found a page that didn't draw after her change: put it back. */
    private function doAutoundo(array $d): void
    {
        $rev = (int)($d['rev'] ?? 0);
        $head = $this->pub->history->head();
        if (!$head || $head['rev'] !== $rev || $head['by'] !== $this->me()) throw new UserError('Someone changed the website since, so it wasn\'t put back automatically.');
        $prev = $rev - 1;
        while ($prev > 0 && !$this->pub->history->snapshot($prev)) $prev--;
        Log::error('auto undo of rev ' . $rev . ': ' . substr((string)($d['why'] ?? ''), 0, 300));
        $this->answer($this->pub->restore($this->me(), $prev, (string)($d['id'] ?? ''), 'autoundo'));
    }

    // ---------------------------------------------------------------- files

    /** The hosting's upload limit, in words, when a file was too big for it. */
    private static function uploadProblem(?array $f, string $what): ?string
    {
        $limit = (string)ini_get('upload_max_filesize');
        $tooBig = "That $what is bigger than this hosting accepts ($limit). Ask John for a smaller copy.";
        if ($f === null) return (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 ? $tooBig : "The $what didn't arrive. Try again.";
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return $tooBig;
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) return "The $what didn't arrive. Try again.";
        return null;
    }

    private function doPhoto(array $d): void
    {
        $f = $_FILES['photo'] ?? null;
        if (($e = self::uploadProblem($f, 'photo')) !== null) throw new UserError($e);
        $r = Photos::accept($this->cfg->paths, (string)$f['tmp_name'], (string)($d['slot'] ?? 'photo'));
        Http::json(['ok' => true] + $r);
    }

    private function doContract(array $d): void
    {
        $state = (string)($d['state'] ?? '');
        if (!isset(Site::STATES[$state])) throw new UserError('Pick the state first.');
        $f = $_FILES['pdf'] ?? null;
        if (($e = self::uploadProblem($f, 'PDF')) !== null) throw new UserError($e);
        $live = $this->pub->live->load();
        foreach ($live['states'] ?? [] as $st) if (($st['code'] ?? '') === $state && !empty($st['pdf'])) Contracts::keepLive($this->cfg->paths, $state, (string)$st['pdf']);
        $r = Contracts::accept($this->cfg->paths, (string)$f['tmp_name'], $state, $this->me(), (string)($f['name'] ?? ''));
        Http::json(['ok' => true, 'permanent' => Contracts::permanentName($state)] + $r);
    }

    private function doContracts(array $q): void
    {
        $state = (string)($q['state'] ?? '');
        $out = [];
        foreach (Contracts::versions($this->cfg->paths, $state) as $v) {
            $out[] = ['v' => $v['v'], 'when' => Clock::when((int)$v['at']), 'by' => $v['by'] ? $this->people->name($v['by']) : null, 'name' => $v['name'] ?? '', 'first' => !empty($v['first'])];
        }
        Http::json(['versions' => $out]);
    }

    private function doContractFile(array $q): void
    {
        $v = (string)($q['v'] ?? '');
        $f = Contracts::file($this->cfg->paths, $v);
        if (!preg_match('/^[a-f0-9]{16}$/', $v) || !is_file($f)) { http_response_code(404); return; }
        Http::headers('api');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="contract-' . $v . '.pdf"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
    }

    // ---------------------------------------------------------------- people

    private function alert(string $subject, array $paras): void
    {
        (new Mailer($this->cfg))->send($this->people->alertEmails(), $subject, $paras, null, 'alert');
    }

    private function peopleOut(): array
    {
        $out = [];
        foreach ($this->people->all() as $p) {
            $out[] = ['id' => $p['id'], 'name' => $p['name'], 'username' => $p['username'], 'email' => $p['email'], 'alerts' => !empty($p['alerts']),
                'removed' => $p['removed'] ? Clock::when((int)$p['removed']) : null, 'rescueLeft' => count($p['rescue'] ?? []),
                'devices' => array_map(fn($d) => ['what' => $d['what'], 'last' => Clock::when((int)$d['last'])], $p['devices'] ?? []), 'me' => $p['id'] === $this->me()];
        }
        return $out;
    }

    private function doPeople(array $q): void
    {
        Http::json(['people' => $this->peopleOut()]);
    }

    private function doPeopleAdd(array $d): void
    {
        $p = $this->people->add((string)($d['name'] ?? ''), (string)($d['username'] ?? ''), (string)($d['email'] ?? ''), null);
        $code = (new Codes($this->cfg))->issue($p['id'], 'reset');
        $this->people->save();
        (new Mailer($this->cfg))->send([$p['email']], 'You can now edit the website', [
            $this->me['name'] . ' added you to the website editor. Your username is: ' . $p['username'],
            "To choose your password, open {$this->cfg->siteUrl()}/edit/?code and type this code: $code (it works for 24 hours).",
        ], null, 'code');
        $this->alert('Someone was added to the website editor', [$this->me['name'] . ' added ' . $p['name'] . ' (' . $p['email'] . ') to the website editor (' . Clock::when(Clock::now()) . ').']);
        Http::json(['ok' => true, 'code' => $code, 'people' => $this->peopleOut()]);
    }

    private function doPeopleUpdate(array $d): void
    {
        $id = (string)($d['id'] ?? '');
        $before = $this->people->find($id);
        if (!$before) throw new UserError('That person is no longer here.');
        $ch = [];
        if (isset($d['name'])) $ch['name'] = trim((string)$d['name']);
        if (isset($d['email'])) $ch['email'] = strtolower(trim((string)$d['email']));
        if (isset($d['alerts'])) $ch['alerts'] = (bool)$d['alerts'];
        $after = $this->people->update($id, $ch);
        $this->people->save();
        $to = array_unique(array_merge($this->people->alertEmails(), [$before['email']]));
        (new Mailer($this->cfg))->send($to, 'A person\'s details changed in the website editor', [
            $this->me['name'] . ' changed ' . $before['name'] . '\'s details (' . Clock::when(Clock::now()) . '):',
            implode("\n", array_filter([
                $before['name'] !== $after['name'] ? "Name: {$before['name']} → {$after['name']}" : '',
                $before['email'] !== $after['email'] ? "Email: {$before['email']} → {$after['email']}" : '',
                !empty($before['alerts']) !== !empty($after['alerts']) ? 'Change alerts: ' . (!empty($after['alerts']) ? 'on' : 'off') : '',
            ])),
        ], null, 'alert');
        Http::json(['ok' => true, 'people' => $this->peopleOut()]);
    }

    /** Remove person: signs them out everywhere and cancels their rescue codes in one step. */
    private function doPeopleRemove(array $d): void
    {
        $id = (string)($d['id'] ?? '');
        $p = $this->people->find($id);
        if (!$p) throw new UserError('That person is no longer here.');
        if ($id === $this->me()) throw new UserError('You can\'t remove yourself. Ask the other person to do it.');
        $this->people->remove($id, $this->me());
        $this->people->save();
        $this->sessions->endAllFor($id);
        (new Codes($this->cfg))->cancel($id);
        $tok = (new Tokens($this->cfg))->make('restore-person', ['person' => $id]);
        (new Mailer($this->cfg))->send($this->people->alertEmails(), $p['name'] . ' was removed from the website editor', [
            $this->me['name'] . ' removed ' . $p['name'] . ' from the website editor (' . Clock::when(Clock::now()) . '). ' . $p['name'] . ' is signed out everywhere and the rescue codes no longer work.',
            'If that was a mistake, tap the button within 30 days to let ' . $p['name'] . ' sign in again.',
        ], ['Let ' . $p['name'] . ' back in', $this->cfg->siteUrl() . '/edit/undo?t=' . $tok], 'alert');
        Http::json(['ok' => true, 'people' => $this->peopleOut()]);
    }

    private function doPeopleRestore(array $d): void
    {
        $id = (string)($d['id'] ?? '');
        $this->people->restore($id);
        $this->people->save();
        $this->alert('Someone was let back into the website editor', [$this->me['name'] . ' let ' . $this->people->name($id) . ' sign in again (' . Clock::when(Clock::now()) . ').']);
        Http::json(['ok' => true, 'people' => $this->peopleOut()]);
    }

    /** "John's own login resets hers": a 6-digit code to give her (also emailed to her). */
    private function doPeopleReset(array $d): void
    {
        $id = (string)($d['id'] ?? '');
        $p = $this->people->find($id);
        if (!$p || !empty($p['removed'])) throw new UserError('That person is no longer here.');
        if ($id === $this->me()) throw new UserError('To change your own password, use "Change my password".');
        $code = (new Codes($this->cfg))->issue($id, 'reset');
        (new Mailer($this->cfg))->send([$p['email']], 'A code to reset your website editor password', [
            $this->me['name'] . ' made you a code to choose a new password: ' . $code,
            "Open {$this->cfg->siteUrl()}/edit/?code, type your username, this code and a new password. The code works for 24 hours.",
        ], null, 'code');
        $this->alert('A password reset was started', [$this->me['name'] . ' made a reset code for ' . $p['name'] . ' (' . Clock::when(Clock::now()) . ').']);
        Http::json(['ok' => true, 'code' => $code, 'hours' => Codes::RESET_HOURS]);
    }

    private function doPeopleRescue(array $d): void
    {
        $codes = $this->people->newRescueCodes($this->me());
        $this->people->save();
        $this->alert('New rescue codes were made', [$this->me['name'] . ' made new rescue codes; the old ones no longer work (' . Clock::when(Clock::now()) . ').']);
        Http::json(['ok' => true, 'codes' => $codes]);
    }

    private function doPassword(array $d): void
    {
        if (!$this->people->checkPassword($this->me, (string)($d['current'] ?? ''))) throw new UserError('Your current password doesn\'t match.');
        $this->people->setPassword($this->me(), (string)($d['new'] ?? ''));
        $this->people->save();
        $this->sessions->endAllFor($this->me(), $this->session['token']);
        $this->alert('A password was changed', [$this->me['name'] . ' changed their password (' . Clock::when(Clock::now()) . '). Other signed-in phones were signed out.']);
        Http::json(['ok' => true]);
    }

    // ---------------------------------------------------------------- words and settings

    private function doWords(array $q): void
    {
        $out = [];
        foreach ($this->words() as $w => $e) $out[] = ['word' => $w, 'by' => $this->people->name($e['by'] ?? null), 'when' => Clock::when((int)($e['at'] ?? 0))];
        usort($out, fn($a, $b) => strcasecmp($a['word'], $b['word']));
        Http::json(['words' => $out]);
    }

    private function doWordsAdd(array $d): void
    {
        $w = trim((string)($d['word'] ?? ''));
        if ($w === '' || strlen($w) > 40 || preg_match('/\s/', $w)) throw new UserError('Teach one word at a time.');
        Lock::with($this->cfg->paths, 'words', function () use ($w) {
            $all = $this->words();
            $all[$w] = ['by' => $this->me(), 'at' => Clock::now()];
            Json::write($this->cfg->paths->private . '/words.json', $all);
        });
        Http::json(['ok' => true, 'words' => array_keys($this->words())]);
    }

    private function doWordsRemove(array $d): void
    {
        $w = (string)($d['word'] ?? '');
        Lock::with($this->cfg->paths, 'words', function () use ($w) {
            $all = $this->words();
            unset($all[$w]);
            Json::write($this->cfg->paths->private . '/words.json', $all);
        });
        Http::json(['ok' => true, 'words' => array_keys($this->words())]);
    }

    private function doPrefs(array $d): void
    {
        $p = $this->prefs();
        foreach ((array)($d['set'] ?? []) as $k => $v) {
            if (is_string($k) && preg_match('/^[a-zA-Z]{1,30}$/', $k) && (is_string($v) || is_bool($v) || is_int($v)) && strlen((string)$v) < 200) $p[$k] = $v;
        }
        Json::write($this->cfg->paths->private . '/prefs/' . $this->me() . '.json', $p, false);
        Http::json(['ok' => true, 'prefs' => $p]);
    }

    private function doSignout(array $d): void
    {
        $this->sessions->end($this->session['token']);
        Http::json(['ok' => true]);
    }
}
