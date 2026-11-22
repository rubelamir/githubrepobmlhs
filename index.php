<?php
declare(strict_types=1);

/**
 * Fallback only: production serves the public site at / via root .htaccess
 * (files still live in bmlhs/). This file runs if rewrite is unavailable.
 */
if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'bmlhs' . DIRECTORY_SEPARATOR . 'index.php')) {
    header('Location: bmlhs/', true, 302);
    exit;
}

http_response_code(503);
echo 'The school website is unavailable.';
