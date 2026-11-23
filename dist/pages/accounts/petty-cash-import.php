<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');

header('Location: petty-cash.php', true, 302);
exit;
