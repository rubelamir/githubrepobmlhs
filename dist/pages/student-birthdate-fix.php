<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');
redirectWithMessage('students.php', 'success', 'জন্মতারিখ সংশোধনের পাতা আর নেই।');
