<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']], 405);
}

try {
    $db = getDatabaseConnection();
    $classes = array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => trim((string) ($row['class_name_ban'] ?? '')) ?: (string) ($row['class_name'] ?? ''),
        ],
        schoolClasses($db, true)
    );
    $classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
    $sections = is_int($classId) && $classId > 0
        ? array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => trim((string) ($row['section_name_ban'] ?? '')) ?: (string) ($row['section_name'] ?? ''),
            ],
            schoolSections($db, $classId, true)
        )
        : [];
    $exams = array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => trim((string) ($row['exam_name_bangla'] ?? '')) ?: (string) ($row['exam_name_english'] ?? ''),
        ],
        schoolPublishedExams($db)
    );
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'public catalog');
}

mobileApiJson([
    'data' => [
        'classes' => $classes,
        'sections' => $sections,
        'published_exams' => $exams,
    ],
]);
