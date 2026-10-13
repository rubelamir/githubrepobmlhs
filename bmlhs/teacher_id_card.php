<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/dist/includes/auth.php';
requireAuthentication('../dist/examples/login-v2.php');

$cardId = publicRecordId('teacher');
$target = '../dist/pages/teacher-id-card.php';
if ($cardId > 0) {
    $target = signedRecordUrl($target, $cardId, 'teacher');
}

header('Location: ' . $target, true, 302);
exit;
