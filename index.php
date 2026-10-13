<?php
$distPath = __DIR__ . DIRECTORY_SEPARATOR . 'bmlhs' . DIRECTORY_SEPARATOR;

if (is_file($distPath . 'index.php')) {
    header('Location: bmlhs/index.php', true, 302);
    exit;
}

http_response_code(503);
echo 'The school website is unavailable.';
