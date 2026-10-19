<?php
// Signing in: the people who can edit, their own session store (cPanel wipes PHP's built-in
// sessions after 24 idle minutes), remembered devices, wrong-password slow-down without a
// hard lockout, emailed 6-digit codes, rescue codes, and one-time links in alert emails.
//
//   <private>/users.json        people (password and rescue codes as hashes only)
//   <private>/sessions/*.json   one file per signed-in browser, named by a hash of its cookie
//   <private>/throttle.json     recent wrong attempts per address / person / device
//   <private>/codes.json        6-digit codes waiting to be typed
//   <private>/tokens.json       one-time links from alert emails (Undo, Sign out everywhere)
declare(strict_types=1);

namespace ThinkSmart;

final class People
{
    private Config $cfg;
    private string $file;
    private array $data;

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
        $this->file = $cfg->paths->private . '/users.json';
        $this->data = Json::read($this->file, ['people' => []]);
        if (!isset($this->data['people']) || !is_array($this->data['people'])) $this->data = ['people' => []];
    }

    public function save(): void
    {
        Json::write($this->file, $this->data);
    }

    /** Everyone, including people removed in the last 30 days (they can be brought back). */
    public function all(): array
    {
        return $this->data['people'];
    }

    public function active(): array
    {
        return array_values(array_filter($this->data['people'], fn($p) => empty($p['removed'])));
    }

    public function find(?string $id): ?array
    {
        foreach ($this->data['people'] as $p) if ($p['id'] === $id) return $p;
        return null;
    }

    /** By username or email, ignoring capitals. Removed people can't sign in. */
    public function findLogin(string $login): ?array
    {
        $l = strtolower(trim($login));
        if ($l === '') return null;
        foreach ($this->active() as $p) {
            if (strtolower($p['username']) === $l || strtolower((string)$p['email']) === $l) return $p;
        }
        return null;
    }

    public function name(?string $id): string
    {
        $p = $id ? $this->find($id) : null;
        return $p ? (string)$p['name'] : 'Someone';
    }

    private function put(array $person): void
    {
        foreach ($this->data['people'] as $i => $p) {
            if ($p['id'] === $person['id']) { $this->data['people'][$i] = $person; return; }
        }
        $this->data['people'][] = $person;
    }

    public static function usernameProblem(string $u): ?string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $u)) return 'Use 3 to 32 letters or numbers for the username (dots, dashes and _ are fine).';
        return null;
    }

    public static function passwordProblem(string $pw, string $username = ''): ?string
    {
        if (strlen($pw) < 8) return 'Use at least 8 characters, or let your iPhone suggest a strong password.';
        if (strlen($pw) > 200) return 'That password is too long.';
        $low = strtolower($pw);
        if ($username !== '' && str_contains($low, strtolower($username))) return "The password can't contain the username.";
        foreach (['password', '12345678', '123456789', 'qwertyui', 'thinksmart', 'iloveyou', 'abcdefgh', '11111111'] as $bad) {
            if (str_contains($low, $bad)) return 'That password is too easy to guess. Let your iPhone suggest one.';
        }
        return null;
    }

    public function add(string $name, string $username, string $email, ?string $password, bool $alerts = false): array
    {
        $username = trim($username);
        if (($e = self::usernameProblem($username)) !== null) throw new UserError($e);
        foreach ($this->all() as $p) {
            if (strtolower($p['username']) === strtolower($username) && empty($p['removed'])) throw new UserError('Someone already has that username.');
        }
        if (trim($name) === '') throw new UserError('Add their name.');
        if (!preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/', trim($email))) throw new UserError('That email address doesn\'t look right.');
        if ($password !== null && ($e = self::passwordProblem($password, $username)) !== null) throw new UserError($e);
        $p = [
            'id' => 'p' . bin2hex(random_bytes(4)),
            'username' => $username,
            'name' => trim($name),
            'email' => strtolower(trim($email)),
            'hash' => $password === null ? '' : password_hash($password, PASSWORD_DEFAULT),
            'alerts' => $alerts,
            'rescue' => [],
            'devices' => [],
            'created' => Clock::now(),
            'removed' => null,
        ];
        $this->put($p);
        return $p;
    }

    public function update(string $id, array $changes): array
    {
        $p = $this->find($id);
        if (!$p) throw new UserError('That person is no longer here.');
        foreach (['name', 'email', 'alerts'] as $k) if (array_key_exists($k, $changes)) $p[$k] = $changes[$k];
        if (isset($changes['email']) && !preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/', (string)$p['email'])) throw new UserError('That email address doesn\'t look right.');
        $this->put($p);
        return $p;
    }

    public function checkPassword(?array $p, string $pw): bool
    {
        // the same work whether or not the person exists
        $hash = $p['hash'] ?? '';
        if ($hash === '') { password_verify($pw, '$2y$10$abcdefghijklmnopqrstuuZ6P3ZVhSWr8u/gN0eCGhCd0vYkE6VpS'); return false; }
        $ok = password_verify($pw, $hash);
        if ($ok && password_needs_rehash($hash, PASSWORD_DEFAULT)) { $p['hash'] = password_hash($pw, PASSWORD_DEFAULT); $this->put($p); $this->save(); }
        return $ok;
    }

    public function setPassword(string $id, string $pw): void
    {
        $p = $this->find($id);
        if (!$p) throw new UserError('That person is no longer here.');
        if (($e = self::passwordProblem($pw, $p['username'])) !== null) throw new UserError($e);
        $p['hash'] = password_hash($pw, PASSWORD_DEFAULT);
        $p['changed'] = Clock::now();
        $this->put($p);
    }

    /** Signs them out everywhere (done by the caller) and cancels their rescue codes. Kept 30 days for Undo. */
    public function remove(string $id, string $by): void
    {
        $p = $this->find($id);
        if (!$p) return;
        if (count($this->active()) <= 1 && empty($p['removed'])) throw new UserError("The last person can't be removed. Add someone else first.");
        $p['removed'] = Clock::now();
        $p['removedBy'] = $by;
        $p['rescue'] = [];
        $p['devices'] = [];
        $this->put($p);
    }

    public function restore(string $id): void
    {
        $p = $this->find($id);
        if (!$p || empty($p['removed'])) return;
        $p['removed'] = null;
        unset($p['removedBy']);
        $this->put($p);
    }

    /** Forget people removed more than 30 days ago. */
    public function forgetOld(): void
    {
        $cut = Clock::now() - 30 * 86400;
        $this->data['people'] = array_values(array_filter($this->data['people'], fn($p) => empty($p['removed']) || $p['removed'] > $cut));
    }

    // -- rescue codes (one on the emergency sheet, one in the Passwords app)

    public const RESCUE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @return list<string> new codes, shown once */
    public function newRescueCodes(string $id, int $n = 2): array
    {
        $p = $this->find($id);
        if (!$p) throw new UserError('That person is no longer here.');
        $codes = [];
        $p['rescue'] = [];
        for ($i = 0; $i < $n; $i++) {
            $c = '';
            for ($k = 0; $k < 12; $k++) $c .= self::RESCUE_ALPHABET[random_int(0, strlen(self::RESCUE_ALPHABET) - 1)];
            $codes[] = substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8, 4);
            $p['rescue'][] = ['hash' => password_hash($c, PASSWORD_DEFAULT), 'made' => Clock::now()];
        }
        $this->put($p);
        return $codes;
    }

    public function useRescueCode(string $id, string $code): bool
    {
        $p = $this->find($id);
        $c = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (!$p || strlen($c) !== 12) return false;
        foreach ($p['rescue'] as $i => $r) {
            if (password_verify($c, $r['hash'])) {
                array_splice($p['rescue'], $i, 1);
                $this->put($p);
                return true;
            }
        }
        return false;
    }

    // -- remembered devices

    public function hasDevice(array $p, ?string $device): bool
    {
        if ($device === null) return false;
        foreach ($p['devices'] ?? [] as $d) if (hash_equals($d['id'], $device)) return true;
        return false;
    }

    /** @return bool true when the device is new for this person */
    public function remember(string $id, string $device, string $what): bool
    {
        $p = $this->find($id);
        if (!$p) return false;
        foreach ($p['devices'] as $i => $d) {
            if (hash_equals($d['id'], $device)) { $p['devices'][$i]['last'] = Clock::now(); $this->put($p); return false; }
        }
        $p['devices'][] = ['id' => $device, 'what' => $what, 'first' => Clock::now(), 'last' => Clock::now()];
        // keep the 12 most recently used
        usort($p['devices'], fn($a, $b) => $b['last'] <=> $a['last']);
        $p['devices'] = array_slice($p['devices'], 0, 12);
        $this->put($p);
        return true;
    }

    public function forgetDevices(string $id): void
    {
        $p = $this->find($id);
        if (!$p) return;
        $p['devices'] = [];
        $this->put($p);
    }

    /** Who gets the change alerts (John). If nobody is set, everyone does. */
    public function alertEmails(?string $except = null): array
    {
        $act = $this->active();
        $to = array_filter($act, fn($p) => !empty($p['alerts']));
        if (!$to) $to = $act;
        return array_values(array_map(fn($p) => (string)$p['email'], array_filter($to, fn($p) => $p['id'] !== $except || count($to) === 1)));
    }
}

/** A message she can read, for something she typed (shown as is). */
final class UserError extends \RuntimeException
{
}

final class Sessions
{
    public const DAYS = 90;
    public const COOKIE = 'ts_s';
    public const DEVICE_COOKIE = 'ts_d';

    private Config $cfg;
    private string $dir;

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
        $this->dir = $cfg->paths->private . '/sessions';
    }

    private function file(string $token): string
    {
        return $this->dir . '/' . substr($this->cfg->hmac('session:' . $token), 0, 40) . '.json';
    }

    /** @return array{0:string,1:array} the cookie value and the session */
    public function start(string $person, ?string $device, string $what): array
    {
        $token = Http::token();
        $s = [
            'person' => $person, 'made' => Clock::now(), 'seen' => Clock::now(),
            'expires' => Clock::now() + self::DAYS * 86400, 'device' => $device, 'what' => $what,
            'csrf' => Http::token(),
        ];
        Json::write($this->file($token), $s, false);
        Http::cookie(self::COOKIE, $token, self::DAYS * 86400);
        if (random_int(1, 20) === 1) $this->gc();
        return [$token, $s];
    }

    /** The signed-in session for this request, renewed on every visit (90 days from now). */
    public function current(): ?array
    {
        $token = (string)($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) return null;
        $f = $this->file($token);
        $s = Json::read($f);
        if (!is_array($s) || ($s['expires'] ?? 0) < Clock::now()) { if (is_file($f)) @unlink($f); return null; }
        if ($s['seen'] < Clock::now() - 300) {
            $s['seen'] = Clock::now();
            $s['expires'] = Clock::now() + self::DAYS * 86400;
            Json::write($f, $s, false);
            Http::cookie(self::COOKIE, $token, self::DAYS * 86400);
        }
        $s['token'] = $token;
        return $s;
    }

    public function end(?string $token): void
    {
        if ($token) @unlink($this->file($token));
        Http::cookie(self::COOKIE, '', -1);
    }

    /** Sign a person out everywhere (except, optionally, this browser). */
    public function endAllFor(string $person, ?string $exceptToken = null): int
    {
        $keep = $exceptToken ? $this->file($exceptToken) : '';
        $n = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if ($f === $keep) continue;
            $s = Json::read($f);
            if (is_array($s) && ($s['person'] ?? '') === $person) { @unlink($f); $n++; }
        }
        return $n;
    }

    public function gc(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $s = Json::read($f);
            if (!is_array($s) || ($s['expires'] ?? 0) < Clock::now()) @unlink($f);
        }
    }

    /** The device cookie: a random id that makes this phone "remembered" after a sign-in. */
    public function device(bool $create = false): ?string
    {
        $v = (string)($_COOKIE[self::DEVICE_COOKIE] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $v)) {
            if (!$create) return null;
            $v = Http::token();
            $_COOKIE[self::DEVICE_COOKIE] = $v;
        }
        Http::cookie(self::DEVICE_COOKIE, $v, 400 * 86400);
        return $this->cfg->hmac('device:' . $v);
    }

    /** "iPhone · Safari" */
    public static function what(string $ua): string
    {
        $dev = 'Computer';
        if (str_contains($ua, 'iPhone')) $dev = 'iPhone';
        elseif (str_contains($ua, 'iPad')) $dev = 'iPad';
        elseif (str_contains($ua, 'Android')) $dev = 'Android phone';
        elseif (str_contains($ua, 'Macintosh')) $dev = 'Mac';
        elseif (str_contains($ua, 'Windows')) $dev = 'Windows computer';
        $br = 'browser';
        if (str_contains($ua, 'Edg/')) $br = 'Edge';
        elseif (str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/')) $br = 'Chrome';
        elseif (str_contains($ua, 'FxiOS') || str_contains($ua, 'Firefox/')) $br = 'Firefox';
        elseif (str_contains($ua, 'Safari/') || str_contains($ua, 'AppleWebKit')) $br = 'Safari';
        return "$dev · $br";
    }
}

/**
 * Wrong passwords slow down, but never lock anyone out for good: after 4 wrong tries in a
 * row, each further one waits 1, 2, 4… up to 60 minutes. Counted per address and per person;
 * a phone that's already remembered for that person is counted on its own instead, so a
 * stranger guessing can't lock the owners out.
 */
final class Throttle
{
    private Config $cfg;
    private string $file;

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
        $this->file = $cfg->paths->private . '/throttle.json';
    }

    public function key(string $kind, string $value): string
    {
        return $kind . ':' . substr($this->cfg->hmac("throttle:$kind:$value"), 0, 24);
    }

    public static function wait(int $n): int
    {
        if ($n < 5) return 0;
        return min(60, 2 ** ($n - 5)) * 60;
    }

    /** When the next try is allowed (0 = now). */
    public function until(array $keys): int
    {
        $d = Json::read($this->file, []);
        $until = 0;
        foreach ($keys as $k) {
            $e = $d[$k] ?? null;
            if (!$e || $e['last'] < Clock::now() - 86400) continue;
            $until = max($until, $e['last'] + self::wait((int)$e['n']));
        }
        return $until > Clock::now() ? $until : 0;
    }

    public function fail(array $keys): int
    {
        return Lock::with($this->cfg->paths, 'throttle', function () use ($keys) {
            $d = Json::read($this->file, []);
            foreach ($d as $k => $e) if ($e['last'] < Clock::now() - 86400) unset($d[$k]);
            foreach ($keys as $k) {
                $n = isset($d[$k]) ? (int)$d[$k]['n'] + 1 : 1;
                $d[$k] = ['n' => $n, 'last' => Clock::now()];
            }
            Json::write($this->file, $d, false);
            return $this->until($keys);
        });
    }

    public function clear(array $keys): void
    {
        Lock::with($this->cfg->paths, 'throttle', function () use ($keys) {
            $d = Json::read($this->file, []);
            foreach ($keys as $k) unset($d[$k]);
            Json::write($this->file, $d, false);
        });
    }
}

/**
 * 6-digit codes: emailed when she forgets her password ("email scanners use up links", so
 * it's a code typed into the same screen), or made by the other person ("John resets hers").
 */
final class Codes
{
    public const EMAIL_MINUTES = 20;
    public const RESET_HOURS = 24;
    public const TRIES = 5;

    private Config $cfg;
    private string $file;

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
        $this->file = $cfg->paths->private . '/codes.json';
    }

    /** Null when too many codes were sent recently. */
    public function issue(string $person, string $kind): ?string
    {
        return Lock::with($this->cfg->paths, 'codes', function () use ($person, $kind) {
            $d = Json::read($this->file, []);
            $e = $d[$person] ?? ['sent' => []];
            $sent = array_values(array_filter($e['sent'] ?? [], fn($t) => $t > Clock::now() - 3600));
            if ($kind === 'email' && (count($sent) >= 6 || ($sent && end($sent) > Clock::now() - 45))) return null;
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $sent[] = Clock::now();
            $d[$person] = [
                'hash' => $this->cfg->hmac("code:$person:$code"), 'kind' => $kind, 'made' => Clock::now(),
                'expires' => Clock::now() + ($kind === 'email' ? self::EMAIL_MINUTES * 60 : self::RESET_HOURS * 3600),
                'tries' => 0, 'sent' => $sent,
            ];
            Json::write($this->file, $d, false);
            return $code;
        });
    }

    /** @return string 'ok' | 'wrong' | 'expired' | 'none' */
    public function check(string $person, string $code): string
    {
        return Lock::with($this->cfg->paths, 'codes', function () use ($person, $code) {
            $d = Json::read($this->file, []);
            $e = $d[$person] ?? null;
            if (!$e || empty($e['hash'])) return 'none';
            if ($e['expires'] < Clock::now()) { $d[$person]['hash'] = ''; Json::write($this->file, $d, false); return 'expired'; }
            $code = (string)preg_replace('/\D/', '', $code);
            if (hash_equals($e['hash'], $this->cfg->hmac("code:$person:$code"))) {
                $d[$person]['hash'] = '';
                Json::write($this->file, $d, false);
                return 'ok';
            }
            $d[$person]['tries'] = (int)$e['tries'] + 1;
            if ($d[$person]['tries'] >= self::TRIES) $d[$person]['hash'] = '';
            Json::write($this->file, $d, false);
            return $d[$person]['hash'] === '' ? 'expired' : 'wrong';
        });
    }

    public function cancel(string $person): void
    {
        Lock::with($this->cfg->paths, 'codes', function () use ($person) {
            $d = Json::read($this->file, []);
            if (isset($d[$person])) { $d[$person]['hash'] = ''; Json::write($this->file, $d, false); }
        });
    }
}

/** One-time links in alert emails: "Undo this change", "Sign her out everywhere". */
final class Tokens
{
    public const DAYS = 30;

    private Config $cfg;
    private string $file;

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
        $this->file = $cfg->paths->private . '/tokens.json';
    }

    public function make(string $action, array $data): string
    {
        $t = Http::token();
        Lock::with($this->cfg->paths, 'tokens', function () use ($t, $action, $data) {
            $d = Json::read($this->file, []);
            foreach ($d as $k => $e) if ($e['expires'] < Clock::now()) unset($d[$k]);
            $d[$this->cfg->hmac("token:$t")] = ['action' => $action, 'data' => $data, 'made' => Clock::now(), 'expires' => Clock::now() + self::DAYS * 86400, 'used' => null];
            Json::write($this->file, $d, false);
        });
        return $t;
    }

    public function peek(string $t): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $t)) return null;
        $d = Json::read($this->file, []);
        $e = $d[$this->cfg->hmac("token:$t")] ?? null;
        return $e && $e['expires'] >= Clock::now() ? $e : null;
    }

    public function use(string $t): ?array
    {
        return Lock::with($this->cfg->paths, 'tokens', function () use ($t) {
            $d = Json::read($this->file, []);
            $k = $this->cfg->hmac("token:$t");
            $e = $d[$k] ?? null;
            if (!$e || $e['expires'] < Clock::now() || $e['used']) return null;
            $d[$k]['used'] = Clock::now();
            Json::write($this->file, $d, false);
            return $e;
        });
    }
}
