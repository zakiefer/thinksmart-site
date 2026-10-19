<?php
// The preview router, behind the session.
//
//   /edit/p/<Page>.dc.html       the page built from her draft (the same bytes for both of
//                                the runtime's passes), with data-edit marks for "Fix a word"
//   /edit/p/live/<Page>.dc.html  the live file as visitors get it, for the check after a save
//   /edit/p/send-lead.php        a stub: a preview never sends a real lead
//   /edit/p/<anything else>      the live file (support.js, vendor, media, docs), or a
//                                contract that isn't live yet
declare(strict_types=1);

namespace ThinkSmart;

final class Preview
{
    /** Settings shown by chips, and where she changes them. */
    public const REF_FIELDS = [
        'phone' => 'site.phone', 'phone2' => 'site.phone2', 'email' => 'site.email', 'address' => 'site.address',
        'addressFooter' => 'site.address', 'callback' => 'site.callback', 'callbackShort' => 'site.callback',
        'price' => 'site.price', 'adjuster' => 'site.adjuster', 'adjusterTitle' => 'site.adjuster',
        'stateCodes' => 'states', 'stateNames' => 'states', 'otherStates' => 'claim.byPhone.otherStates',
        'guaranteeShort' => 'site.guarantee', 'guaranteeLong' => 'site.guarantee', 'servicesCount' => 'services.items',
        'noticeUntil' => 'notice.end',
    ];

    private const DERIVED = [
        'derived.licenseLine' => 'chrome.footer.licenseAuto', 'derived.homeTopLine' => 'home.top.kickerAuto',
        'derived.ticker' => 'home.marquee.phrases', 'derived.notice' => 'notice',
    ];

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    private const TYPES = [
        'js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css', 'webp' => 'image/webp', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'mp4' => 'video/mp4',
        'pdf' => 'application/pdf', 'woff2' => 'font/woff2', 'json' => 'application/json', 'txt' => 'text/plain', 'xml' => 'application/xml',
    ];

    private Config $cfg;
    private Publisher $pub;
    private array $schema;

    public function __construct(Config $cfg, Publisher $pub)
    {
        $this->cfg = $cfg;
        $this->pub = $pub;
        $this->schema = Schema::load($cfg->paths);
    }

    /** Her draft applied to the live content (her version wins wherever both changed). */
    public function draftContent(string $person): array
    {
        $live = $this->pub->live->load();
        $d = $this->pub->drafts->get($person);
        $resolve = [];
        foreach ($d['ops'] as $op) $resolve[Changes::key($op['path'])] = 'mine';
        [$c] = Changes::apply($live, $d['ops'], $resolve);
        return Changes::ensureIds($c, $this->pub->live->lists());
    }

    public function serve(string $person, string $rel): void
    {
        $rel = ltrim($rel, '/');
        if ($rel === '' ) $rel = 'Home.dc.html';
        if (preg_match('#(^|/)\.|\.\.|\\\\|[\x00-\x1F\x7F]#', $rel)) { http_response_code(404); return; }
        if ($rel === 'send-lead.php' || str_ends_with($rel, '/send-lead.php')) {
            Http::headers('api');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => 'ok', 'preview' => true]);
            return;
        }
        $live = str_starts_with($rel, 'live/');
        $file = $live ? substr($rel, 5) : $rel;
        $pages = array_values(Site::PAGES);
        if (in_array($file, $pages, true)) {
            if ($live) { $this->sendLive($file); return; }
            $b = new Build($this->cfg->paths);
            try {
                $html = $b->renderOne($this->draftContent($person), $file, $trace);
            } catch (BuildError $e) {
                Log::error('preview: ' . $e->getMessage());
                Http::headers('preview');
                http_response_code(200);
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><meta charset="utf-8"><body style="font:17px -apple-system,sans-serif;padding:24px">This page can\'t be shown yet: something in the changes isn\'t finished (for example a state that hasn\'t been picked).</body>';
                return;
            }
            if (str_ends_with($file, '.dc.html')) $html = $this->annotate($html, $trace);
            Http::headers('preview');
            header('Content-Type: ' . (str_ends_with($file, '.xml') ? 'application/xml' : 'text/html') . '; charset=utf-8');
            echo $html;
            return;
        }
        // a contract that isn't live yet
        if (preg_match('~^docs/([a-z0-9._-]+\.pdf)$~', $file)) {
            foreach ($this->draftContent($person)['states'] ?? [] as $st) {
                if (($st['pdf'] ?? '') === $file && !empty($st['pdfV']) && is_file($src = Contracts::file($this->cfg->paths, (string)$st['pdfV']))) {
                    Http::headers('preview');
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline');
                    readfile($src);
                    return;
                }
            }
        }
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) { http_response_code(404); return; }
        if ($live && $ext === 'mp4') { http_response_code(204); return; } // the check never downloads videos
        // everything else is the live file, served by the web server itself
        header('Cache-Control: private, max-age=60');
        header('Location: /' . str_replace('%2F', '/', rawurlencode($file)), true, 302);
    }

    /** The live file as it is, plus a tiny recorder for the runtime's errors (the check reads it). */
    private function sendLive(string $file): void
    {
        $f = $this->cfg->paths->webroot . '/' . $file;
        if (!is_file($f)) { http_response_code(404); return; }
        $html = (string)file_get_contents($f);
        $hook = '<script src="/edit/app/check-hook.js"></script>';
        $html = preg_replace('/<head([^>]*)>/i', '<head$1>' . $hook, $html, 1) ?? $html;
        Http::headers('preview');
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    /** Where a value from the template trace is changed in the editor (a field or a list item). */
    public function editPath(?string $abs): ?string
    {
        if ($abs === null || $abs === '') return null;
        if (preg_match('/^ref\.(\w+)$/', $abs, $m)) return self::REF_FIELDS[$m[1]] ?? null;
        foreach (self::DERIVED as $k => $v) if ($abs === $k || str_starts_with($abs, "$k.")) return $v;
        if (str_starts_with($abs, 'derived.') || str_starts_with($abs, 'page')) return null;
        $seg = explode('.', $abs);
        for ($k = count($seg); $k > 0; $k--) {
            $cand = implode('.', array_slice($seg, 0, $k));
            $sp = Schema::pathOf($this->schema, $cand);
            if (isset($this->schema['fields'][$sp])) return $cand;
            // a list item ("states.2")
            if (preg_match('/^\d+$/', $seg[$k - 1]) && $k > 1) {
                $parent = Schema::pathOf($this->schema, implode('.', array_slice($seg, 0, $k - 1)));
                if (in_array($this->schema['fields'][$parent]['type'] ?? '', ['list', 'checklist'], true)) return $cand;
            }
        }
        return null;
    }

    /**
     * Mark the element around each value with data-edit="<where it's changed>", so a tap in the
     * preview finds its box. Only inside <x-dc> (what the visitor sees), never in its <helmet>.
     */
    public function annotate(string $html, array $trace): string
    {
        $byStart = $trace;
        usort($byStart, fn($a, $b) => $a[2] <=> $b[2]);
        $toks = Html::tokenize($html);
        $marks = [];   // insertion offset => paths
        $stack = [];   // [name, insertAt]
        $inDc = false;
        $helmet = 0;
        $ti = 0;
        $nt = count($byStart);
        $mark = function (?array $el, ?string $path) use (&$marks) {
            if ($el === null || $path === null) return;
            $marks[$el[1]][$path] = true;
        };
        foreach ($toks as $tok) {
            $top = $stack ? $stack[count($stack) - 1] : null;
            $tagEl = null;
            if ($tok['t'] === 'tag') {
                $close = $html[$tok['e'] - 2] === '/' ? $tok['e'] - 2 : $tok['e'] - 1;
                $tagEl = [$tok['name'], $close];
            }
            while ($ti < $nt && $byStart[$ti][2] < $tok['e']) {
                $t = $byStart[$ti++];
                if ($t[2] < $tok['s'] || !$inDc || $helmet > 0) continue;
                $path = $this->editPath($t[4] ?? null);
                if (in_array($t[1], ['attr', 'url'], true)) $mark($tagEl, $path);
                elseif ($t[1] === 'text') $mark($top, $path);
            }
            if ($tok['t'] === 'tag') {
                $name = $tok['name'];
                if ($name === 'x-dc') { $inDc = true; continue; }
                if ($name === 'helmet') { $helmet++; continue; }
                if ($tok['self'] || in_array($name, self::VOID, true)) continue;
                $ok = (bool)preg_match('/^[a-z][a-z0-9]*$/', $name) && !in_array($name, ['script', 'style', 'template', 'svg', 'path'], true);
                // components and drawings aren't marked: words inside them mark the element around them
                $stack[] = $ok ? [$name, $tagEl[1]] : $top;
                continue;
            }
            if ($tok['t'] === 'end') {
                if ($tok['name'] === 'x-dc') { $inDc = false; continue; }
                if ($tok['name'] === 'helmet') { $helmet = max(0, $helmet - 1); continue; }
                if (in_array($tok['name'], self::VOID, true)) continue;
                array_pop($stack);
            }
        }
        if (!$marks) return $html;
        krsort($marks);
        foreach ($marks as $at => $paths) {
            $html = substr($html, 0, $at) . ' data-edit="' . Http::esc(implode(' ', array_keys($paths))) . '"' . substr($html, $at);
        }
        return $html;
    }
}
