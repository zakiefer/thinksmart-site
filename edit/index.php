<?php
// The website editor: /edit (sign in, the app, its API, previews, the installer). See lib/Editor.php.
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The website editor needs PHP 8.1 or newer (this hosting runs ' . PHP_VERSION . '). In cPanel, open MultiPHP Manager and pick the newest PHP.';
    exit;
}
require __DIR__ . '/lib/load.php';
ThinkSmart\Editor::run();
