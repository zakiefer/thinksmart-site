<?php
// Builds the whole site into a private side folder, checks it, and swaps it in with a
// written journal so an interrupted swap is finished (or undone) by the next run.
//
//   <private>/builds/<id>/site/…        the built files
//   <private>/builds/<id>/filters.json  own phone numbers/emails for the chat and lead mailer
//   <private>/builds/<id>/manifest.json file => sha256, bytes
//   <private>/builds/<id>/backup/…      the live files a publish replaced
//   <private>/swap-journal.json         the publish in progress
declare(strict_types=1);

namespace ThinkSmart;

final class Build
{
    private Paths $paths;
    public array $warnings = [];

    public function __construct(Paths $paths)
    {
        $this->paths = $paths;
        Rich::useStyles($paths->templates() . '/rich-styles.php');
    }

    /** Render every page from the content. @return array<string,string> file => text */
    public function render(array $content, ?array &$traces = null): array
    {
        $content = Content::clean($content);
        $eng = new Template($this->paths->templates(), Site::refResolver());
        $derived = Derive::all($content);
        $out = [];
        $traces = [];
        foreach (Site::PAGES as $name => $file) {
            $root = $content + ['page' => $name, 'derived' => $derived];
            $trace = [];
            $out[$file] = $eng->render("$name.html", $root, $trace);
            $traces[$file] = $trace;
        }
        return $out;
    }

    /** Render one file (for the editor's preview). */
    public function renderOne(array $content, string $file, ?array &$trace = null): string
    {
        $name = array_search($file, Site::PAGES, true);
        if ($name === false) throw new BuildError("$file is not a page");
        $content = Content::clean($content);
        $eng = new Template($this->paths->templates(), Site::refResolver());
        $root = $content + ['page' => $name, 'derived' => Derive::all($content)];
        $trace = [];
        return $eng->render("$name.html", $root, $trace);
    }

    /**
     * Build into a new side folder and check it. Throws BuildError (with every problem
     * listed) if a check fails; the live site is never touched here.
     * @return array{id: string, dir: string, files: array<string, array{sha: string, bytes: int}>}
     */
    public function build(array $content, array $extra = []): array
    {
        $bad = Content::problems(Content::clean($content));
        if ($bad) throw new BuildError("The content needs fixing first:\n  " . implode("\n  ", $bad));
        $files = $this->render($content, $traces);
        $bindings = Lint::bindingsIn($this->paths->templates());
        $errors = [];
        $this->warnings = [];
        foreach ($files as $file => $text) {
            $r = Lint::check($file, $text, $traces[$file], $bindings);
            foreach ($r['errors'] as $e) $errors[] = "$file: $e";
            foreach ($r['warnings'] as $w) $this->warnings[] = "$file: $w";
        }
        if ($errors) throw new BuildError("The build didn't pass its checks:\n  " . implode("\n  ", $errors));

        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $dir = $this->paths->builds() . '/' . $id;
        $manifest = [];
        foreach ($files as $file => $text) {
            Files::writeAtomic("$dir/site/$file", $text);
            $manifest[$file] = ['sha' => hash('sha256', $text), 'bytes' => strlen($text)];
        }
        // files copied as they are (a new contract PDF at its permanent address)
        foreach ($extra as $file => $src) {
            if (!preg_match('~^docs/[a-z0-9._-]+\.pdf$~', $file)) throw new BuildError("Refusing to place $file");
            $data = (string)@file_get_contents($src);
            if (strncmp($data, '%PDF-', 5) !== 0) throw new BuildError("$src is not a PDF");
            Files::writeAtomic("$dir/site/$file", $data);
            $manifest[$file] = ['sha' => hash('sha256', $data), 'bytes' => strlen($data)];
        }
        // re-read what was written: the disk must hold exactly what was built
        foreach ($manifest as $file => $m) {
            if (Files::sha("$dir/site/$file") !== $m['sha']) throw new BuildError("Side folder copy of $file doesn't match");
        }
        Files::writeAtomic("$dir/filters.json", json_encode(self::filters(Content::clean($content)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        Files::writeAtomic("$dir/manifest.json", json_encode(['id' => $id, 'built' => gmdate('c'), 'files' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return ['id' => $id, 'dir' => $dir, 'files' => $manifest, 'traces' => $traces];
    }

    /** Own phone numbers and email: the chat and the lead mailer don't treat them as a visitor's. */
    public static function filters(array $c): array
    {
        $phones = array_values(array_unique(array_filter([
            preg_replace('/\D/', '', (string)($c['site']['phone'] ?? '')),
            preg_replace('/\D/', '', (string)($c['site']['phone2'] ?? '')),
        ])));
        return ['ownPhones' => $phones, 'ownEmails' => array_values(array_filter([strtolower((string)($c['site']['email'] ?? ''))]))];
    }

    /** Remove old side folders, keeping the newest $keep (and any the journal still needs). */
    public function prune(int $keep = 5): void
    {
        $dirs = glob($this->paths->builds() . '/*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        $j = Journal::read($this->paths);
        foreach (array_slice($dirs, $keep) as $d) {
            if ($j && ($j['build'] ?? '') === $d) continue;
            Files::rmTree($d);
        }
    }
}

/**
 * The swap journal. Before anything live changes, the journal records each file's old and
 * new fingerprint. Each live file is replaced by a same-folder rename, so a visitor only ever
 * gets the old or the new file whole. If PHP stops halfway, recover() looks at each live file:
 * new = done, old = still to do; then it finishes the swap, or puts back every old file if
 * the build can't be trusted. It never touches files outside the build (.htaccess,
 * .user.ini, .well-known, cgi-bin, media, contracts…).
 */
final class Journal
{
    public static function read(Paths $p): ?array
    {
        if (!is_file($p->journal())) return null;
        $j = json_decode((string)file_get_contents($p->journal()), true);
        return is_array($j) ? $j : null;
    }

    private static function write(Paths $p, array $j): void
    {
        Files::writeAtomic($p->journal(), json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** Swap a checked build into the live folder. */
    public static function publish(Paths $p, array $build, ?callable $stopAfter = null): void
    {
        self::recover($p);
        $files = [];
        foreach ($build['files'] as $file => $m) {
            if (preg_match('#(^|/)\.|\.\.|^(cgi-bin|\.well-known)/#', $file)) throw new BuildError("Refusing to write $file");
            $files[] = ['file' => $file, 'new' => $m['sha'], 'old' => Files::sha($p->webroot . '/' . $file)];
        }
        $j = ['build' => $build['dir'], 'state' => 'swapping', 'started' => gmdate('c'), 'files' => $files];
        // keep copies of the live files first
        foreach ($files as $f) {
            $live = $p->webroot . '/' . $f['file'];
            if ($f['old'] !== null) {
                $bk = $build['dir'] . '/backup/' . $f['file'];
                Files::writeAtomic($bk, (string)file_get_contents($live));
                if (Files::sha($bk) !== $f['old']) throw new BuildError("Backup of {$f['file']} didn't copy cleanly");
            }
        }
        self::write($p, $j);
        foreach ($files as $i => $f) {
            self::put($p, $build['dir'], $f);
            if ($stopAfter && $stopAfter($i)) return; // test hook: simulate PHP stopping here
        }
        self::finish($p, $j);
    }

    private static function put(Paths $p, string $buildDir, array $f): void
    {
        $live = $p->webroot . '/' . $f['file'];
        if (Files::sha($live) === $f['new']) return;
        $src = $buildDir . '/site/' . $f['file'];
        if (Files::sha($src) !== $f['new']) throw new BuildError("Built copy of {$f['file']} is missing or changed");
        Files::writeAtomic($live, (string)file_get_contents($src));
    }

    /** Check every live file, install the own-number filters for the mailer, mark the journal done. */
    private static function finish(Paths $p, array $j): void
    {
        foreach ($j['files'] as $f) {
            if (Files::sha($p->webroot . '/' . $f['file']) !== $f['new']) throw new BuildError("Live {$f['file']} doesn't match what was built");
        }
        if (is_file($j['build'] . '/filters.json')) Files::writeAtomic($p->private . '/filters.json', (string)file_get_contents($j['build'] . '/filters.json'));
        $j['state'] = 'done';
        $j['finished'] = gmdate('c');
        self::write($p, $j);
    }

    /** Finish an interrupted swap, or put the old files back if the build can't be used. */
    public static function recover(Paths $p): string
    {
        $j = self::read($p);
        if (!$j || ($j['state'] ?? '') !== 'swapping') return 'nothing to recover';
        $usable = true;
        foreach ($j['files'] as $f) {
            if (Files::sha($j['build'] . '/site/' . $f['file']) !== $f['new']) $usable = false;
        }
        if ($usable) {
            foreach ($j['files'] as $f) self::put($p, $j['build'], $f);
            self::finish($p, $j);
            return 'finished the interrupted swap';
        }
        self::rollback($p, $j);
        return 'put the previous files back';
    }

    /** Put every file of a journal back to what was live before it. */
    public static function rollback(Paths $p, ?array $j = null): void
    {
        $j = $j ?? self::read($p);
        if (!$j) throw new BuildError('Nothing to undo');
        foreach ($j['files'] as $f) {
            $live = $p->webroot . '/' . $f['file'];
            if ($f['old'] === null) { if (is_file($live) && Files::sha($live) === $f['new']) @unlink($live); continue; }
            $bk = $j['build'] . '/backup/' . $f['file'];
            if (Files::sha($bk) !== $f['old']) throw new BuildError("Backup of {$f['file']} is missing");
            if (Files::sha($live) !== $f['old']) Files::writeAtomic($live, (string)file_get_contents($bk));
        }
        $j['state'] = 'rolledback';
        $j['finished'] = gmdate('c');
        self::write($p, $j);
    }
}
