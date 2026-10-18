<?php
// Loads the site builder (core PHP only: no mbstring, iconv, ctype or image extensions needed).
declare(strict_types=1);

foreach (['Html', 'Encode', 'Rich', 'Template', 'Site', 'Grid', 'Notice', 'Derive', 'Lint', 'Store', 'Build'] as $f) {
    require_once __DIR__ . "/$f.php";
}
