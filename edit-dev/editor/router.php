<?php
// Router for PHP's built-in server, doing what edit/.htaccess and the root .htaccess do on GoDaddy:
//   TS_WEBROOT=<dir>/site TS_PRIVATE_DIR=<dir>/private php -S localhost:8790 -t <dir>/site edit-dev/editor/router.php
declare(strict_types=1);

$uri = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = (string)getenv('TS_WEBROOT');
$app = dirname(__DIR__, 2) . '/edit';
if ($uri === '/edit') { header('Location: /edit/', true, 301); return true; }
if (str_starts_with($uri, '/edit/')) {
    if (str_starts_with($uri, '/edit/app/')) {
        $f = $app . '/app/' . substr($uri, 10);
        if (!is_file($f) || str_contains($uri, '..') || basename($f)[0] === '.') { http_response_code(404); return true; }
        $types = ['js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css', 'png' => 'image/png', 'svg' => 'image/svg+xml',
            'webmanifest' => 'application/manifest+json', 'json' => 'application/json', 'txt' => 'text/plain; charset=utf-8', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'gz' => 'application/gzip'];
        header('Content-Type: ' . ($types[strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        header('Cache-Control: no-cache');
        header('X-Content-Type-Options: nosniff');
        readfile($f);
        return true;
    }
    if ($uri === '/edit/' || $uri === '/edit/index.php' || in_array($uri, ['/edit/undo', '/edit/confirm', '/edit/install'], true)
        || str_starts_with($uri, '/edit/install/') || str_starts_with($uri, '/edit/p/') || str_starts_with($uri, '/edit/api/')) {
        require $app . '/index.php';
        return true;
    }
    http_response_code(403);
    return true;
}
if ($uri === '/') { readfile("$root/Home.dc.html"); return true; }
// the real lead mailer runs only when this test site's mail goes to the outbox (nothing is ever sent)
if (str_starts_with($uri, '/send-lead.php')) {
    $cfg = json_decode((string)@file_get_contents(getenv('TS_PRIVATE_DIR') . '/config.json'), true);
    if (($cfg['mail'] ?? '') !== 'outbox') { http_response_code(403); echo 'not in tests'; return true; }
    require $root . '/send-lead.php';
    return true;
}
$f = $root . $uri;
if (!is_file($f)) { http_response_code(404); readfile("$root/404.html"); return true; }
if (str_ends_with($uri, '.mjs')) { header('Content-Type: text/javascript'); readfile($f); return true; }
return false;
