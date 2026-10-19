<?php
// One-tap sign-in for the local test copy only (served by router.php at /dev-login), so trying the
// editor needs no password. Refuses unless: PHP's built-in server, a request from this Mac, and a
// private folder made by dev-setup.php (dev on, mail to the outbox). Never deployed: edit-dev/ is export-ignore.
declare(strict_types=1);

require dirname(__DIR__, 2) . '/edit/lib/load.php';

use ThinkSmart\{Config, Http, People, Paths, Sessions};

$cfg = json_decode((string)@file_get_contents(getenv('TS_PRIVATE_DIR') . '/config.json'), true);
$local = PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$local || ($cfg['dev'] ?? false) !== true || ($cfg['mail'] ?? '') !== 'outbox') {
    http_response_code(403);
    echo 'Only for the local test copy.';
    return;
}

$config = Config::load(Paths::detect());
$people = new People($config);

if (Http::method() === 'POST') {
    $person = $people->findLogin((string)($_POST['as'] ?? ''));
    if ($person) {
        (new Sessions($config))->start($person['id'], null, 'Test copy (one-tap sign-in)');
        header('Location: /edit/', true, 303);
        return;
    }
}

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
$buttons = '';
foreach ($people->active() as $p) {
    $buttons .= '<button name="as" value="' . $h($p['username']) . '">Sign in as ' . $h($p['name']) . '</button>';
}
header('Content-Type: text/html; charset=utf-8');
echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Test copy · Sign in</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0B0E13; font: 16px/1.5 -apple-system, system-ui, sans-serif; color: #1a1a1a; }
  main { background: #fff; border-radius: 16px; padding: 28px 24px; width: min(360px, calc(100vw - 32px)); box-sizing: border-box; }
  p.k { margin: 0 0 6px; color: #9a5a00; font-weight: 600; font-size: 14px; }
  h1 { margin: 0 0 6px; font-size: 24px; }
  p.n { margin: 0 0 20px; color: #555; font-size: 14px; }
  button { display: block; width: 100%; margin: 0 0 12px; padding: 16px; border: 0; border-radius: 12px; background: #F09A2E; color: #111; font: 600 17px/1 inherit; cursor: pointer; }
  button:hover { background: #f5ab4e; }
</style></head>
<body><main>
  <p class="k">Think Smart · Website editor</p>
  <h1>Try the editor</h1>
  <p class="n">A test copy on this computer. Changes here never touch the real website, and no email is sent.</p>
  <form method="post">{$buttons}</form>
</main></body></html>
HTML;
