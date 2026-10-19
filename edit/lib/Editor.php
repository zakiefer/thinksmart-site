<?php
// The editor's front door (edit/index.php): https only, then sign-in pages, the app, the
// JSON API, the preview router, and the one-time links from alert emails.
declare(strict_types=1);

namespace ThinkSmart;

final class Editor
{
    public static function run(): void
    {
        ini_set('display_errors', '0');
        $paths = Paths::detect();
        $cfg = Config::load($paths);
        Log::to($paths->private . '/editor-errors.log');
        set_error_handler(function (int $no, string $msg, string $file, int $line) {
            Log::error("php: $msg @$file:$line");
            return true;
        });
        Http::$dev = $cfg->dev();

        $people = new People($cfg);
        $sessions = new Sessions($cfg);
        $pages = new Pages($cfg, $people, $sessions);

        if (!$cfg->ready() || !is_dir($paths->private) || !$people->active()) {
            $pages->notice('Not set up yet', 'The website editor hasn\'t been set up on this hosting yet.', 503, '');
            return;
        }
        if (!Http::isHttps() && !($cfg->dev() && Http::isLocal())) {
            $pages->notice('Use the secure address', 'Open the editor with https:// at the start of the address.', 403, '');
            return;
        }

        $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/edit/'), PHP_URL_PATH);
        $route = (string)preg_replace('#^/edit#', '', rawurldecode($uri));
        if ($route === '' || $route === '/index.php') $route = '/';
        $session = $sessions->current();
        if ($session && !$people->find($session['person'])) { $sessions->end($session['token']); $session = null; }
        if ($session && !empty($people->find($session['person'])['removed'])) { $sessions->end($session['token']); $session = null; }

        if (str_starts_with($route, '/api/')) {
            if (!$session) { Http::json(['error' => 'You\'re signed out. Sign in again; your changes are kept.', 'signin' => true], 401); return; }
            (new Api($cfg, $people, $sessions, $session))->handle(substr($route, 5));
            return;
        }
        if (str_starts_with($route, '/p/')) {
            if (!$session) { http_response_code(401); Http::headers('preview'); echo 'Signed out.'; return; }
            $pub = new Publisher($cfg, $people);
            (new Preview($cfg, $pub))->serve($session['person'], substr($route, 3));
            return;
        }
        if ($route === '/undo') {
            self::link($cfg, $people, $sessions, $pages);
            return;
        }
        if ($route !== '/') { $pages->notice('Not found', 'That page isn\'t part of the editor.', 404); return; }
        if (!$session) {
            $pages->handle(new Throttle($cfg), new Codes($cfg), new Mailer($cfg));
            return;
        }
        if (Http::method() === 'POST' && ($_POST['do'] ?? '') === 'signout') {
            $sessions->end($session['token']);
            header('Location: /edit/?out', true, 303);
            return;
        }
        $pages->shell();
    }

    /** "Undo this change" / "Sign her out everywhere" / "Let her back in" from an alert email. */
    private static function link(Config $cfg, People $people, Sessions $sessions, Pages $pages): void
    {
        $t = (string)($_GET['t'] ?? ($_POST['t'] ?? ''));
        $tokens = new Tokens($cfg);
        $e = $tokens->peek($t);
        if (!$e || $e['used']) {
            $pages->notice('This link has been used', 'This link was already used or has expired. To undo a change, sign in and open "Undo a change".');
            return;
        }
        $pub = new Publisher($cfg, $people);
        $pub->settle();
        $alerts = array_values(array_filter($people->active(), fn($p) => !empty($p['alerts'])));
        $by = $alerts[0]['id'] ?? ($people->active()[0]['id'] ?? '');
        $what = '';
        if ($e['action'] === 'undo') {
            $entry = $pub->history->get((int)$e['data']['rev']);
            if (!$entry) { $pages->notice('Too old', 'That change isn\'t in History any more.'); return; }
            $what = $people->name($entry['by']) . ', ' . Clock::when($entry['at']) . ":\n" . implode("\n", array_map(fn($l) => "• $l", $entry['lines']));
            $button = 'Undo this change';
        } elseif ($e['action'] === 'signout') {
            $what = 'Sign ' . $people->name($e['data']['person']) . ' out of the website editor on every phone and computer.';
            $button = 'Sign out everywhere';
        } elseif ($e['action'] === 'restore-person') {
            $what = 'Let ' . $people->name($e['data']['person']) . ' sign in to the website editor again.';
            $button = 'Let them back in';
        } else {
            $pages->notice('This link has been used', 'This link isn\'t valid any more.');
            return;
        }
        $f = (string)($_COOKIE['ts_f'] ?? '');
        if (Http::method() === 'POST') {
            if (!Http::sameOrigin() || $f === '' || !hash_equals($f, (string)($_POST['f'] ?? '')) || !($e = $tokens->use($t))) {
                $pages->notice('Try again', 'That didn\'t go through. Open the link from the email again.');
                return;
            }
            if ($e['action'] === 'undo') {
                $rev = (int)$e['data']['rev'];
                $inv = $pub->inverse($rev) ?? [];
                $all = [];
                foreach ($inv as $op) $all[Changes::key($op['path'])] = 'mine';
                $r = $pub->undo($by, $rev, $all, '', 'undo');
                if (!$r['ok']) { $pages->notice('Not undone', (string)($r['error'] ?? 'It couldn\'t be undone from the email. Sign in and use "Undo a change".')); return; }
                $pages->notice('Undone', 'The change was undone at ' . Clock::time(Clock::now()) . '. It\'s in History as an undo.', 200, '<a href="/edit/">Open the editor</a>');
                return;
            }
            if ($e['action'] === 'signout') {
                $id = (string)$e['data']['person'];
                $sessions->endAllFor($id);
                $people->forgetDevices($id);
                $people->save();
                $pages->notice('Signed out everywhere', $people->name($id) . ' has to sign in again everywhere. If someone else may know the password, reset it in People.', 200);
                return;
            }
            $people->restore((string)$e['data']['person']);
            $people->save();
            $pages->notice('Let back in', $people->name((string)$e['data']['person']) . ' can sign in again with their password.', 200);
            return;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $f)) { $f = Http::token(); Http::cookie('ts_f', $f, 86400); }
        Http::headers('page');
        header('Content-Type: text/html; charset=utf-8');
        echo Pages::layout($button, '<main class="signin"><div class="signin-card"><div class="signin-brand"><span class="dot"></span>Think Smart · Website editor</div>'
            . '<h1>' . Http::esc($button) . '?</h1><p class="pre">' . Http::esc($what) . '</p>'
            . '<form method="post" action="/edit/undo" data-once><input type="hidden" name="t" value="' . Http::esc($t) . '"><input type="hidden" name="f" value="' . Http::esc($f) . '">'
            . '<button class="btn primary big" type="submit">' . Http::esc($button) . '</button></form><p class="small"><a href="/edit/">Open the editor instead</a></p></div></main>');
    }
}
