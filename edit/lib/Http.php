<?php
// Requests and responses for the editor: cookies, security headers, JSON, and reading saves
// sent as a file part of a multipart form (so GoDaddy's firewall doesn't block text that
// looks like code, and its 128 KB limit on plain form fields doesn't apply).
declare(strict_types=1);

namespace ThinkSmart;

final class Http
{
    public static bool $dev = false;
    /** Trust X-Forwarded-Proto only when the hosting sits behind a proxy (config "trustProxy"). */
    public static bool $trustProxy = false;

    /** 32 random bytes, URL-safe (43 characters). */
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
        return self::$trustProxy && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function isLocal(): bool
    {
        $h = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        return in_array($h, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($h, '.localhost') || str_ends_with($h, '.test');
    }

    public static function cookie(string $name, string $value, int $maxAge): void
    {
        if (headers_sent()) return;
        setcookie($name, $value, [
            'expires' => $maxAge < 0 ? 1 : Clock::now() + $maxAge,
            'path' => '/edit',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function ua(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
    }

    public static function origin(): string
    {
        return (self::isHttps() ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** A POST must come from the editor's own pages. */
    public static function sameOrigin(): bool
    {
        $o = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($o !== null && $o !== 'null' && strtolower($o) !== strtolower(self::origin())) return false;
        if ($o === 'null') return false;
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($site !== null && !in_array($site, ['same-origin', 'none'], true)) return false;
        return true;
    }

    /**
     * kind: page (the editor and its sign-in pages: strict, no inline scripts, no framing),
     *       preview (a draft page of the website: its own scripts run, but it can't send
     *       anything anywhere and only the editor may frame it), api (JSON).
     */
    public static function headers(string $kind): void
    {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        if (self::isHttps()) header('Strict-Transport-Security: max-age=31536000');
        if ($kind === 'preview') {
            header("Content-Security-Policy: connect-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
            header('X-Frame-Options: SAMEORIGIN');
            return;
        }
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; "
            . "connect-src 'self'; frame-src 'self'; worker-src 'self' blob:; manifest-src 'self'; media-src 'self' blob:; "
            . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
    }

    public static function json($data, int $code = 200): void
    {
        self::headers('api');
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** The JSON a save sent as its "data" file part (or a plain field, for small requests). */
    public static function data(): array
    {
        $raw = null;
        if (isset($_FILES['data']['tmp_name']) && is_uploaded_file((string)$_FILES['data']['tmp_name'])) {
            $raw = (string)file_get_contents((string)$_FILES['data']['tmp_name']);
        } elseif (isset($_POST['data'])) {
            $raw = (string)$_POST['data'];
        } elseif (str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $raw = (string)file_get_contents('php://input');
        }
        if ($raw === null || $raw === '') return [];
        $v = json_decode($raw, true);
        if (!is_array($v)) throw new UserError("The change didn't arrive whole. Try again.");
        return $v;
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
