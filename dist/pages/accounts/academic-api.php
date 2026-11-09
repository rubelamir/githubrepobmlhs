<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

// Section lookup for accounting filter forms (dist/js/student-filter.js), limited to the
// sections the user may work with in the accounting module.
$access = accBootPage(Capability::DASHBOARD_VIEW);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ((string) ($_GET['type'] ?? '') !== 'sections') {
    http_response_code(400);
    echo '[]';
    exit;
}

$rows = array_map(
    static fn (array $section): array => [
        'id' => (int) $section['id'],
        'section_name' => (string) ($section['section_name'] ?? ''),
        'section_name_ban' => (string) ($section['section_name_ban'] ?? ''),
    ],
    accSectionsFor($access, (int) ($_GET['class_id'] ?? 0))
);
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
