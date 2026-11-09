<?php

declare(strict_types=1);

/**
 * Entry point of the accounting module. Requires dist/includes/auth.php
 * (database, session and panel-role helpers) to be loaded first.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'School\\Accounting\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/functions.php';
