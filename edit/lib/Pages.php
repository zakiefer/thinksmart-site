<?php
// The editor's own pages that work without JavaScript: sign in (made for iPhone Passwords
// and Face ID autofill), forgot password (a 6-digit code by email, or a code from the other
// person), rescue code, the "Undo" link from alert emails, and the app's shell.
declare(strict_types=1);

namespace ThinkSmart;

final class Pages
{
    private Config $cfg;
    private People $people;
    private Sessions $sessions;

    public function __construct(Config $cfg, People $people, Sessions $sessions)
    {
        $this->cfg = $cfg;
        $this->people = $people;
        $this->sessions = $sessions;
    }

    private static function v(string $file): string
    {
        $f = dirname(__DIR__) . '/app/' . $file;
        return 'app/' . $file . '?v=' . (is_file($f) ? substr(md5((string)filemtime($f) . filesize($f)), 0, 8) : '0');
    }

    public static function layout(string $title, string $body, string $script = 'signin.js'): string
    {
        $e = [Http::class, 'esc'];
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<title>' . $e($title) . '</title>'
            . '<meta name="theme-color" content="#0B0E13"><meta name="robots" content="noindex, nofollow">'
            . '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">'
            . '<meta name="apple-mobile-web-app-title" content="Website"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'
            . '<link rel="manifest" href="' . self::v('manifest.webmanifest') . '">'
            . '<link rel="apple-touch-icon" href="' . self::v('icon-180.png') . '">'
            . '<link rel="icon" href="' . self::v('icon-180.png') . '">'
            . '<link rel="stylesheet" href="' . self::v('app.css') . '">'
            . ($script !== '' ? '<script type="module" src="' . self::v($script) . '"></script>' : '')
            . '</head><body>' . $body . '</body></html>';
    }

    private static function out(string $html, int $code = 200): void
    {
        Http::headers('page');
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    /** A form token (double-submit cookie), so another site can't post these forms. */
    private function formToken(): string
    {
        $t = (string)($_COOKIE['ts_f'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $t)) { $t = Http::token(); Http::cookie('ts_f', $t, 86400); $_COOKIE['ts_f'] = $t; }
        return $t;
    }

    private function formOk(): bool
    {
        $t = (string)($_COOKIE['ts_f'] ?? '');
        return Http::method() === 'POST' && Http::sameOrigin() && $t !== '' && hash_equals($t, (string)($_POST['f'] ?? ''));
    }

    private function card(string $inner, string $cls = ''): string
    {
        return '<main class="signin ' . $cls . '"><div class="signin-card">'
            . '<div class="signin-brand"><span class="dot"></span>Think Smart · Website editor</div>' . $inner . '</div></main>';
    }

    private static function msg(?string $m, string $kind = 'bad'): string
    {
        return $m ? '<p class="msg ' . $kind . '" role="alert">' . Http::esc($m) . '</p>' : '';
    }

    // ---------------------------------------------------------------- pages

    public function signIn(?string $msg = null, string $username = '', string $kind = 'bad'): void
    {
        $f = $this->formToken();
        $this->sessions->device(true);
        $e = [Http::class, 'esc'];
        self::out(self::layout('Sign in · Website editor', $this->card(
            '<h1>Sign in</h1>' . self::msg($msg, $kind)
            . '<form method="post" action="/edit/" class="stack" data-once>'
            . '<input type="hidden" name="do" value="signin"><input type="hidden" name="f" value="' . $e($f) . '">'
            . '<label class="field"><span>Username</span><input name="username" id="username" value="' . $e($username) . '" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required enterkeyhint="next"></label>'
            . '<label class="field"><span>Password</span><span class="pw"><input name="password" id="password" type="password" autocomplete="current-password" required enterkeyhint="go"><button type="button" class="show-pw" aria-label="Show password">Show</button></span></label>'
            . '<button class="btn primary big" type="submit">Sign in</button>'
            . '</form>'
            . '<p class="small"><a href="/edit/?forgot">Forgot your password?</a></p>'
        )));
    }

    public function forgot(?string $msg = null): void
    {
        $f = $this->formToken();
        $e = [Http::class, 'esc'];
        self::out(self::layout('Forgot password · Website editor', $this->card(
            '<h1>Forgot your password?</h1>' . self::msg($msg)
            . '<p>Type your username or email. We\'ll email you a 6-digit code to type in here.</p>'
            . '<form method="post" action="/edit/" class="stack" data-once>'
            . '<input type="hidden" name="do" value="forgot"><input type="hidden" name="f" value="' . $e($f) . '">'
            . '<label class="field"><span>Username or email</span><input name="login" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required></label>'
            . '<button class="btn primary big" type="submit">Email me a code</button></form>'
            . '<h2>Other ways back in</h2>'
            . '<ul class="ways"><li>Ask the other person who edits the website to reset your password from their phone (People, in More). They\'ll give you a 6-digit code. <a href="/edit/?code">I have a code</a></li>'
            . '<li>Use a rescue code from the emergency sheet or your Passwords app. <a href="/edit/?rescue">Use a rescue code</a></li></ul>'
            . '<p class="small"><a href="/edit/">Back to sign in</a></p>'
        )));
    }

    public function code(string $login = '', ?string $msg = null, bool $sent = false, string $kind = 'bad'): void
    {
        $f = $this->formToken();
        $e = [Http::class, 'esc'];
        self::out(self::layout('Type your code · Website editor', $this->card(
            '<h1>' . ($sent ? 'Check your email' : 'Type your code') . '</h1>' . self::msg($msg, $kind)
            . ($sent ? '<p>If that username or email is right, a 6-digit code is on its way. It can take a minute; check Spam too. The code works for 20 minutes.</p>' : '<p>Type the 6-digit code from the email, or the one you were given.</p>')
            . '<form method="post" action="/edit/" class="stack" data-once>'
            . '<input type="hidden" name="do" value="code"><input type="hidden" name="f" value="' . $e($f) . '">'
            . '<label class="field"><span>Username or email</span><input name="login" value="' . $e($login) . '" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required></label>'
            . '<label class="field"><span>6-digit code</span><input name="code" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" maxlength="7" required class="code"></label>'
            . '<label class="field"><span>New password</span><span class="pw"><input name="password" type="password" autocomplete="new-password" minlength="8" required><button type="button" class="show-pw" aria-label="Show password">Show</button></span>'
            . '<small>At least 8 characters. Your iPhone can suggest a strong one and save it.</small></label>'
            . '<button class="btn primary big" type="submit">Save new password and sign in</button></form>'
            . '<p class="small"><a href="/edit/?forgot">Send a new code</a> · <a href="/edit/">Back to sign in</a></p>'
        )));
    }

    public function rescue(?string $msg = null, string $login = ''): void
    {
        $f = $this->formToken();
        $e = [Http::class, 'esc'];
        self::out(self::layout('Rescue code · Website editor', $this->card(
            '<h1>Use a rescue code</h1>' . self::msg($msg)
            . '<p>The rescue code is on the emergency sheet, and one is saved in your Passwords app. Each code works once.</p>'
            . '<form method="post" action="/edit/" class="stack" data-once>'
            . '<input type="hidden" name="do" value="rescue"><input type="hidden" name="f" value="' . $e($f) . '">'
            . '<label class="field"><span>Username</span><input name="login" value="' . $e($login) . '" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required></label>'
            . '<label class="field"><span>Rescue code</span><input name="rescue" autocapitalize="characters" autocorrect="off" spellcheck="false" autocomplete="off" placeholder="ABCD-EFGH-JKLM" required class="code"></label>'
            . '<label class="field"><span>New password</span><span class="pw"><input name="password" type="password" autocomplete="new-password" minlength="8" required><button type="button" class="show-pw" aria-label="Show password">Show</button></span></label>'
            . '<button class="btn primary big" type="submit">Save new password and sign in</button></form>'
            . '<p class="small"><a href="/edit/">Back to sign in</a></p>'
        )));
    }

    public function notice(string $title, string $text, int $code = 200, string $link = '<a href="/edit/">Open the editor</a>'): void
    {
        self::out(self::layout($title, $this->card('<h1>' . Http::esc($title) . '</h1><p>' . Http::esc($text) . '</p><p class="small">' . $link . '</p>')), $code);
    }

    public function shell(): void
    {
        self::out(self::layout('Website editor', '<div id="app" class="app"><div class="loading">Opening the editor…</div></div>'
            . '<noscript><main class="signin"><div class="signin-card"><p>The editor needs JavaScript. In Safari: Settings → Safari → Advanced → JavaScript.</p></div></main></noscript>', 'app.js'));
    }

    // ---------------------------------------------------------------- handling the forms

    /** GET/POST on /edit/ when nobody is signed in. */
    public function handle(Throttle $throttle, Codes $codes, Mailer $mail): void
    {
        $q = (string)($_SERVER['QUERY_STRING'] ?? '');
        if (Http::method() !== 'POST') {
            if ($q === 'forgot') { $this->forgot(); return; }
            if ($q === 'code') { $this->code(); return; }
            if ($q === 'rescue') { $this->rescue(); return; }
            $this->signIn($q === 'out' ? 'You\'re signed out.' : null, '', 'ok');
            return;
        }
        if (!$this->formOk()) { $this->signIn('That page was open too long. Please try again.'); return; }
        $do = (string)($_POST['do'] ?? '');
        $login = trim((string)($_POST['login'] ?? ($_POST['username'] ?? '')));
        $device = $this->sessions->device(true);
        $person = $this->people->findLogin($login);
        $remembered = $person && $this->people->hasDevice($person, $device);
        $keys = $remembered ? [$throttle->key('dev', $device)]
            : [$throttle->key('ip', Http::ip()), $person ? $throttle->key('person', $person['id']) : $throttle->key('login', strtolower($login))];
        $until = $throttle->until($keys);
        $retry = fn(int $t) => 'Too many tries. Try again at ' . Clock::time($t) . '.';

        if ($do === 'signin') {
            if ($until) { $this->signIn($retry($until), $login); return; }
            $pw = (string)($_POST['password'] ?? '');
            if (!$this->people->checkPassword($person, $pw)) {
                $t = $throttle->fail($keys);
                $this->signIn('That username and password don\'t match. Check them and try again.' . ($t ? ' ' . $retry($t) : ''), $login);
                return;
            }
            $throttle->clear($keys);
            $this->signedIn($person, $device, $mail);
            return;
        }
        if ($do === 'forgot') {
            // the same answer whether or not the username exists
            if ($person && !$until) {
                $code = $codes->issue($person['id'], 'email');
                if ($code !== null) {
                    $mail->send([$person['email']], 'Your code for the website editor: ' . $code, [
                        "Your code is $code",
                        'Type it into the "Type your code" screen with a new password. It works for 20 minutes.',
                        'If you didn\'t ask for this, you can ignore this email: your password hasn\'t changed.',
                    ], null, 'code');
                }
            }
            $this->code($login, null, true, 'ok');
            return;
        }
        if ($do === 'code') {
            if ($until) { $this->code($login, $retry($until)); return; }
            $pw = (string)($_POST['password'] ?? '');
            if ($person && ($bad = People::passwordProblem($pw, $person['username'])) !== null) { $this->code($login, $bad); return; }
            $r = $person ? $codes->check($person['id'], (string)($_POST['code'] ?? '')) : 'none';
            if ($r !== 'ok') {
                $t = $throttle->fail($keys);
                $m = $r === 'expired' ? 'That code has expired or was tried too many times. Send a new one.' : 'That code doesn\'t match. Check the newest email and try again.';
                $this->code($login, $m . ($t ? ' ' . $retry($t) : ''));
                return;
            }
            $this->people->setPassword($person['id'], $pw);
            $this->people->save();
            $throttle->clear($keys);
            $this->sessions->endAllFor($person['id']);
            $this->alertPeople($mail, $person, $person['name'] . ' set a new password with a 6-digit code.');
            $this->signedIn($person, $device, $mail, true);
            return;
        }
        if ($do === 'rescue') {
            if ($until) { $this->rescue($retry($until), $login); return; }
            $pw = (string)($_POST['password'] ?? '');
            if ($person && ($bad = People::passwordProblem($pw, $person['username'])) !== null) { $this->rescue($bad . ' Your rescue code still works.', $login); return; }
            if (!$person || !$this->people->useRescueCode($person['id'], (string)($_POST['rescue'] ?? ''))) {
                $t = $throttle->fail($keys);
                $this->rescue('That rescue code doesn\'t match (each one works once).' . ($t ? ' ' . $retry($t) : ''), $login);
                return;
            }
            $this->people->setPassword($person['id'], $pw);
            $this->people->save();
            $throttle->clear($keys);
            $this->sessions->endAllFor($person['id']);
            $left = count($this->people->find($person['id'])['rescue'] ?? []);
            $this->alertPeople($mail, $person, $person['name'] . " used a rescue code to get back in ($left left). New rescue codes can be made in People.");
            $this->signedIn($person, $device, $mail, true);
            return;
        }
        $this->signIn();
    }

    /** Start the session; email John about a sign-in from a new device. */
    private function signedIn(array $person, string $device, Mailer $mail, bool $known = false): void
    {
        $what = Sessions::what(Http::ua());
        $new = $this->people->remember($person['id'], $device, $what);
        $this->people->save();
        $this->sessions->start($person['id'], $device, $what);
        if ($new && !$known) {
            $tok = (new Tokens($this->cfg))->make('signout', ['person' => $person['id']]);
            $to = $this->people->alertEmails();
            $mail->send($to, $person['name'] . ' signed in on a new device', [
                $person['name'] . " signed in to the website editor on a new device: $what, " . Clock::when(Clock::now()) . '.',
                'If that was ' . $person['name'] . ', there\'s nothing to do. If not, tap the button: it signs ' . $person['name'] . ' out everywhere. Then reset the password from People.',
            ], ['Sign ' . $person['name'] . ' out everywhere', $this->cfg->siteUrl() . '/edit/undo?t=' . $tok], 'alert');
        }
        header('Location: /edit/', true, 303);
        Http::headers('page');
    }

    private function alertPeople(Mailer $mail, array $person, string $what): void
    {
        $mail->send($this->people->alertEmails(), 'Sign-in change on the website editor', [$what . ' (' . Clock::when(Clock::now()) . ')', 'If that wasn\'t expected, sign in and check People.'], null, 'alert');
    }
}
