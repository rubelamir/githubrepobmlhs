<?php
$distPath = __DIR__ . DIRECTORY_SEPARATOR . 'bmlhs' . DIRECTORY_SEPARATOR;

if (is_file($distPath . 'index.php')) {
    header('Location: bmlhs/', true, 301);
    exit;
}

http_response_code(503);
echo 'The school website is unavailable.';
