<?php
// Loads the site builder and the editor (core PHP only: no mbstring, iconv, ctype or image extensions needed).
declare(strict_types=1);

foreach (['Html', 'Encode', 'Rich', 'Template', 'Site', 'Grid', 'Notice', 'Derive', 'Lint', 'Store', 'Build',
    'Changes', 'App', 'Mail', 'Http', 'Auth', 'Live', 'Explain', 'Validate', 'Uploads', 'Publisher', 'Preview', 'Pages', 'Api', 'Editor'] as $f) {
    require_once __DIR__ . "/$f.php";
}
