<?php
declare(strict_types=1);

/**
 * Gives every class a sort order once, so the academic sequence no longer has
 * to be hardcoded. `cls.id` cannot be trusted: class Five was added after the
 * others and carries the highest id.
 */
function seedAcademicOrdering(PDO $db): void
{
    if (!tableExists($db, 'cls') || !columnExists($db, 'cls', 'sort_order')) {
        return;
    }

    if ((int) $db->query('SELECT COUNT(*) FROM cls WHERE sort_order > 0')->fetchColumn() === 0) {
        $ranks = [
            'play' => 1, 'nursery' => 2, 'kg' => 3,
            'one' => 4, 'two' => 5, 'three' => 6, 'four' => 7, 'five' => 8,
            'six' => 9, 'seven' => 10, 'eight' => 11, 'nine' => 12, 'ten' => 13,
            'eleven' => 14, 'twelve' => 15,
        ];
        $update = $db->prepare('UPDATE cls SET sort_order = :sort WHERE id = :id');
        $unknown = 50;
        foreach (fetchRows($db, 'SELECT id, class_name FROM cls ORDER BY id') as $row) {
            $name = strtolower(str_replace([' ', '-', 'class'], '', (string) $row['class_name']));
            $update->execute([
                'sort' => ($ranks[$name] ?? ++$unknown) * 10,
                'id' => (int) $row['id'],
            ]);
        }
    }

    if (
        columnExists($db, 'sections', 'sort_order')
        && (int) $db->query('SELECT COUNT(*) FROM sections WHERE sort_order > 0')->fetchColumn() === 0
    ) {
        $db->exec('UPDATE sections SET sort_order = id * 10');
    }
}

function schoolClasses(PDO $db, bool $activeOnly = false): array
{
    if (!tableExists($db, 'cls')) {
        return [];
    }
    $where = $activeOnly && columnExists($db, 'cls', 'is_active') ? 'WHERE is_active = 1' : '';
    $order = columnExists($db, 'cls', 'sort_order') ? 'sort_order ASC, id ASC' : 'id ASC';

    return fetchRows($db, "SELECT * FROM cls {$where} ORDER BY {$order}");
}

function schoolSections(PDO $db, ?int $classId = null, bool $activeOnly = false): array
{
    if (!tableExists($db, 'sections')) {
        return [];
    }
    $conditions = [];
    $params = [];
    if ($classId !== null && $classId !== 0) {
        $conditions[] = 'class_id = :id';
        $params['id'] = $classId;
    }
    if ($activeOnly && columnExists($db, 'sections', 'is_active')) {
        $conditions[] = 'is_active = 1';
    }
    $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
    $order = columnExists($db, 'sections', 'sort_order')
        ? 'class_id ASC, sort_order ASC, id ASC'
        : 'class_id ASC, id ASC';

    return fetchRows($db, "SELECT * FROM sections {$where} ORDER BY {$order}", $params);
}

/**
 * Rows in other tables that would be orphaned if this class or section were
 * removed. Section 17 was once deleted while 99 students still referenced it.
 */
function classUsageCounts(PDO $db, int $classId): array
{
    return [
        'sections' => tableCount($db, 'sections', 'class_id = ' . $classId),
        'students' => tableCount($db, 'students_info', 'cls = ' . $classId),
        'subjects' => tableCount($db, 'class_subjects', 'class_id = ' . $classId)
            + tableCount($db, 'subjects', 'cls_id = ' . $classId),
        'routine' => tableCount($db, 'class_routine', 'class_id = ' . $classId),
        'marks' => tableCount($db, 'student_marks', 'class_id = ' . $classId)
            + tableCount($db, 'marks', 'cls_id = ' . $classId),
    ];
}

function sectionUsageCounts(PDO $db, int $sectionId): array
{
    return [
        'students' => tableCount($db, 'students_info', 'sections = ' . $sectionId),
        'subjects' => tableCount($db, 'class_subjects', 'section_id = ' . $sectionId)
            + tableCount($db, 'subjects', 'sections_id = ' . $sectionId),
        'routine' => tableCount($db, 'class_routine', 'section_id = ' . $sectionId),
        'marks' => tableCount($db, 'student_marks', 'section_id = ' . $sectionId)
            + tableCount($db, 'marks', 'sections_id = ' . $sectionId),
    ];
}

function usageSummary(array $counts): string
{
    $labels = [
        'sections' => 'sections',
        'students' => 'students',
        'subjects' => 'subject lists',
        'routine' => 'routine entries',
        'marks' => 'marks',
    ];
    $parts = [];
    foreach ($counts as $key => $count) {
        if ((int) $count > 0) {
            $parts[] = (int) $count . ' ' . ($labels[$key] ?? $key);
        }
    }

    return implode(', ', $parts);
}

/**
 * Copies the legacy wide tables into `class_subjects` / `student_marks` once.
 *
 * The legacy `marks.subjects_id` is not trusted: 1000 of 1700 rows point at a
 * `subjects` row belonging to another class/section. Every marks row does have
 * exactly one `subjects` row matching its own cls_id + sections_id, so the
 * subject names are resolved that way instead.
 *
 * Nothing is written back to `subjects` or `marks`; they stay as a fallback.
 */
function migrateLegacySubjectsAndMarks(PDO $db): void
{
    if (!tableExists($db, 'class_subjects') || !tableExists($db, 'subjects')) {
        return;
    }
    if (function_exists('schemaMigrationApplied') && schemaMigrationApplied($db, 'legacy_subjects_marks_v1')) {
        return;
    }
    // Already copied on an earlier request. Re-scanning `marks` on every admin
    // page load was taking ~100s (ON DUPLICATE KEY UPDATE of 16k+ rows).
    if (
        tableExists($db, 'student_marks')
        && (int) $db->query('SELECT COUNT(*) FROM class_subjects')->fetchColumn() > 0
        && (int) $db->query('SELECT COUNT(*) FROM student_marks')->fetchColumn() > 0
    ) {
        if (function_exists('recordSchemaMigration')) {
            recordSchemaMigration($db, 'legacy_subjects_marks_v1');
        }
        return;
    }

    $insertSubject = $db->prepare(
        'INSERT INTO class_subjects
            (class_id, section_id, subject_name, subject_name_ban, has_mcq, has_cq, sort_order, legacy_slot)
         VALUES (:class_id, :section_id, :name, :name_ban, :has_mcq, :has_cq, :sort_order, :legacy_slot)'
    );
    $findSubject = $db->prepare(
        'SELECT id FROM class_subjects
         WHERE class_id = :class_id AND section_id = :section_id AND legacy_slot = :legacy_slot
         LIMIT 1'
    );

    $slotIds = [];
    foreach (fetchRows($db, 'SELECT class_id, section_id, legacy_slot, id FROM class_subjects WHERE legacy_slot IS NOT NULL') as $row) {
        $slotIds[(int) $row['class_id']][(int) $row['section_id']][(int) $row['legacy_slot']] = (int) $row['id'];
    }

    foreach (fetchRows($db, 'SELECT * FROM subjects') as $row) {
        $classId = (int) $row['cls_id'];
        $sectionId = (int) $row['sections_id'];
        for ($slot = 1; $slot <= 14; $slot++) {
            $name = trim((string) ($row['s' . $slot] ?? ''));
            if ($name === '') {
                continue;
            }
            if (isset($slotIds[$classId][$sectionId][$slot])) {
                continue;
            }
            $insertSubject->execute([
                'class_id' => $classId,
                'section_id' => $sectionId,
                'name' => $name,
                'name_ban' => $name,
                'has_mcq' => 1,
                'has_cq' => $slot === 8 ? 0 : 1,
                'sort_order' => $slot * 10,
                'legacy_slot' => $slot,
            ]);
            $newId = (int) $db->lastInsertId();
            if ($newId === 0) {
                $findSubject->execute([
                    'class_id' => $classId,
                    'section_id' => $sectionId,
                    'legacy_slot' => $slot,
                ]);
                $newId = (int) $findSubject->fetchColumn();
            }
            if ($newId > 0) {
                $slotIds[$classId][$sectionId][$slot] = $newId;
            }
        }
    }

    if (!tableExists($db, 'marks') || !tableExists($db, 'student_marks')) {
        return;
    }

    $insertMark = $db->prepare(
        'INSERT INTO student_marks
            (student_id, cls_roll, class_id, section_id, session_year, exam_id, class_subject_id, mcq, cq)
         VALUES (:student_id, :cls_roll, :class_id, :section_id, :session_year, :exam_id, :class_subject_id, :mcq, :cq)
         ON DUPLICATE KEY UPDATE mcq = VALUES(mcq), cq = VALUES(cq), student_id = COALESCE(VALUES(student_id), student_id)'
    );
    $insertRemarks = $db->prepare(
        'INSERT INTO student_exam_remarks
            (cls_roll, class_id, section_id, session_year, exam_id, remarks)
         VALUES (:cls_roll, :class_id, :section_id, :session_year, :exam_id, :remarks)
         ON DUPLICATE KEY UPDATE remarks = VALUES(remarks)'
    );
    $findStudent = $db->prepare(
        'SELECT id FROM students_info
         WHERE cls_roll = :roll AND cls = :class_id AND sections = :section_id
         LIMIT 1'
    );

    foreach (fetchRows($db, 'SELECT * FROM marks') as $mark) {
        $classId = (int) $mark['cls_id'];
        $sectionId = (int) $mark['sections_id'];
        $slots = $slotIds[$classId][$sectionId] ?? null;
        if ($slots === null) {
            continue;
        }

        $roll = (string) $mark['cls_roll'];
        $sessionYear = (string) ($mark['session_year'] ?? '');
        $examId = (int) $mark['exam_details_id'];

        $findStudent->execute(['roll' => $roll, 'class_id' => $classId, 'section_id' => (string) $sectionId]);
        $studentId = $findStudent->fetchColumn();

        foreach ($slots as $slot => $classSubjectId) {
            $mcq = trim((string) ($mark['s' . $slot . '_mcq'] ?? ''));
            $cq = trim((string) ($mark['s' . $slot . '_cq'] ?? ''));
            if ($mcq === '' && $cq === '') {
                continue;
            }
            $insertMark->execute([
                'student_id' => $studentId === false ? null : (int) $studentId,
                'cls_roll' => $roll,
                'class_id' => $classId,
                'section_id' => $sectionId,
                'session_year' => $sessionYear,
                'exam_id' => $examId,
                'class_subject_id' => $classSubjectId,
                'mcq' => $mcq === '' ? null : (float) $mcq,
                'cq' => $cq === '' ? null : (float) $cq,
            ]);
        }

        $remarks = trim((string) ($mark['remarks'] ?? ''));
        if ($remarks !== '') {
            $insertRemarks->execute([
                'cls_roll' => $roll,
                'class_id' => $classId,
                'section_id' => $sectionId,
                'session_year' => $sessionYear,
                'exam_id' => $examId,
                'remarks' => $remarks,
            ]);
        }
    }

    if (function_exists('recordSchemaMigration')) {
        recordSchemaMigration($db, 'legacy_subjects_marks_v1');
    }
}

/**
 * Subjects taught in a class/section, in admin-defined order.
 */
function classSubjects(PDO $db, int $classId, int $sectionId, bool $activeOnly = true): array
{
    if (!tableExists($db, 'class_subjects') || $classId <= 0) {
        return [];
    }
    $where = $activeOnly ? ' AND is_active = 1' : '';
    $own = fetchRows(
        $db,
        "SELECT * FROM class_subjects
         WHERE class_id = :class_id AND section_id = :section_id{$where}
         ORDER BY sort_order ASC, id ASC",
        ['class_id' => $classId, 'section_id' => $sectionId]
    );
    if ($own !== [] || $sectionId === 0) {
        return $own;
    }

    return fetchRows(
        $db,
        "SELECT * FROM class_subjects
         WHERE class_id = :class_id AND section_id = 0{$where}
         ORDER BY sort_order ASC, id ASC",
        ['class_id' => $classId]
    );
}

function admissionClasses(PDO $db): array
{
    $classes = schoolClasses($db, true);
    if (!columnExists($db, 'cls', 'open_for_admission')) {
        return $classes;
    }

    return array_values(array_filter(
        $classes,
        static fn (array $class): bool => (int) ($class['open_for_admission'] ?? 1) === 1
    ));
}

function isAdmissionClass(PDO $db, $classId): bool
{
    $id = (string) $classId;
    if ($id === '') {
        return false;
    }

    foreach (admissionClasses($db) as $class) {
        if ((string) $class['id'] === $id) {
            return true;
        }
    }

    return false;
}

/**
 * Consecutive years from $latestYear back $yearsBack years (inclusive).
 *
 * @return array<int, string>
 */
function academicYearOptions($selected = '', ?int $latestYear = null, int $yearsBack = 5): array
{
    $latest = $latestYear ?? (int) date('Y');
    $years = range($latest, $latest - $yearsBack);
    $selectedYear = (int) $selected;
    if ($selectedYear !== 0 && !in_array($selectedYear, $years, true)) {
        $years[] = $selectedYear;
        rsort($years, SORT_NUMERIC);
    }

    $options = [];
    foreach ($years as $year) {
        $options[$year] = bnDigits((string) $year);
    }

    return $options;
}

function echoAcademicYearOptions($selected = '', ?int $latestYear = null, int $yearsBack = 5): void
{
    foreach (academicYearOptions($selected, $latestYear, $yearsBack) as $year => $label) {
        $isSelected = (string) $selected !== '' && (string) $selected === (string) $year;
        echo '<option value="' . $year . '"' . ($isSelected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
}

function echoAdmissionSessionYearOptions($selected = ''): void
{
    echoAcademicYearOptions($selected, (int) date('Y') + 1, 5);
}

function echoPreviousSchoolYearOptions($selected = ''): void
{
    echoAcademicYearOptions($selected, (int) date('Y'), 5);
}

function echoClassOptions(array $classes, $selected = ''): void
{
    foreach ($classes as $class) {
        $id = (int) $class['id'];
        $label = (string) ($class['class_name_ban'] ?: $class['class_name']);
        $isSelected = (string) $selected !== '' && (string) $selected === (string) $id;
        echo '<option value="' . $id . '"' . ($isSelected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
}

function echoSectionOptions(array $sections, $selected = '', bool $includePending = false): void
{
    if ($includePending) {
        $isPending = (string) $selected === 'others' || $selected === '';
        echo '<option value="others"' . ($isPending && $selected === 'others' ? ' selected' : '') . '>নতুন ভর্তি</option>';
    }
    foreach ($sections as $section) {
        $id = (int) $section['id'];
        $label = (string) ($section['section_name_ban'] ?: $section['section_name']);
        $isSelected = (string) $selected === (string) $id;
        echo '<option value="' . $id . '"' . ($isSelected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
}

function subjectUsageCounts(PDO $db, int $subjectId): array
{
    return [
        'marks' => tableCount($db, 'student_marks', 'class_subject_id = ' . $subjectId),
    ];
}

function seedClassPeriods(PDO $db): void
{
    if (!tableExists($db, 'class_periods')) {
        return;
    }
    if ((int) $db->query('SELECT COUNT(*) FROM class_periods')->fetchColumn() > 0) {
        return;
    }
    $hasLabel = columnExists($db, 'class_periods', 'period_label');
    $sql = $hasLabel
        ? 'INSERT INTO class_periods (period_no, period_time, period_label, sort_order, is_active) VALUES (:no, :time, :label, :sort, 1)'
        : 'INSERT INTO class_periods (period_no, period_time, sort_order, is_active) VALUES (:no, :time, :sort, 1)';
    $insert = $db->prepare($sql);
    $labels = defaultPeriodLabels();
    foreach (defaultPeriods() as $periodNo => $time) {
        $params = [
            'no' => $periodNo,
            'time' => $time,
            'sort' => $periodNo * 10,
        ];
        if ($hasLabel) {
            $params['label'] = $labels[$periodNo] ?? ('ঘন্টা ' . bnDigits((string) $periodNo));
        }
        $insert->execute($params);
    }
}

function schoolPeriods(PDO $db): array
{
    $meta = schoolPeriodMeta($db);
    $map = [];
    foreach ($meta as $periodNo => $row) {
        $map[$periodNo] = (string) $row['time'];
    }

    return $map !== [] ? $map : defaultPeriods();
}

function schoolExams(PDO $db, bool $activeOnly = false): array
{
    if (!tableExists($db, 'exam_details')) {
        return [];
    }
    $where = $activeOnly && columnExists($db, 'exam_details', 'is_active') ? 'WHERE is_active = 1' : '';
    $order = columnExists($db, 'exam_details', 'sort_order') ? 'sort_order ASC, id ASC' : 'id ASC';

    return fetchRows($db, "SELECT * FROM exam_details {$where} ORDER BY {$order}");
}

function schoolPublishedExams(PDO $db): array
{
    return array_values(array_filter(
        schoolExams($db, true),
        static fn (array $exam): bool => (int) ($exam['public_results_enabled'] ?? 0) === 1
    ));
}

function examRoutineSubjectLabel(array $subject): string
{
    $bn = trim((string) ($subject['subject_name_ban'] ?? ''));
    if ($bn !== '') {
        return $bn;
    }

    return trim((string) ($subject['subject_name'] ?? ''));
}

function examRoutineRows(
    PDO $db,
    int $examId,
    string $sessionYear,
    int $classId = 0,
    int $sectionId = 0
): array {
    if (!tableExists($db, 'exam_routine') || $examId < 1 || $sessionYear === '') {
        return [];
    }

    $sql = 'SELECT er.*, e.exam_name_bangla, e.exam_name_english,
                   c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name
            FROM exam_routine er
            LEFT JOIN exam_details e ON er.exam_id = e.id
            LEFT JOIN cls c ON er.class_id = c.id
            LEFT JOIN sections sec ON er.section_id = sec.id
            WHERE er.exam_id = :exam_id AND er.session_year = :session_year';
    $params = ['exam_id' => $examId, 'session_year' => $sessionYear];
    if ($classId > 0) {
        $sql .= ' AND er.class_id = :class_id';
        $params['class_id'] = $classId;
    }
    if ($sectionId > 0) {
        $sql .= ' AND (er.section_id = :section_id OR er.section_id = 0)';
        $params['section_id'] = $sectionId;
    }
    $sql .= ' ORDER BY er.exam_date ASC, er.start_time ASC, er.sort_order ASC, er.id ASC';

    return fetchRows($db, $sql, $params);
}

function examRoutineTimeLabel(string $start, string $end): string
{
    $start = examRoutineClock12($start);
    $end = examRoutineClock12($end);
    if ($start !== '' && $end !== '') {
        return $start . ' – ' . $end;
    }

    return $start !== '' ? $start : ($end !== '' ? $end : '');
}

function examRoutineClock12(string $time): string
{
    $time = trim($time);
    if ($time === '') {
        return '';
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m) !== 1) {
        return $time;
    }
    $hour = (int) $m[1];
    if ($hour > 23) {
        return $time;
    }
    $suffix = $hour >= 12 ? 'PM' : 'AM';
    $hour12 = $hour % 12 === 0 ? 12 : $hour % 12;

    return $hour12 . ':' . $m[2] . ' ' . $suffix;
}

function examRoutineDateBn(string $date): string
{
    $date = trim($date);
    if ($date === '' || str_starts_with($date, '0000-00-00')) {
        return '';
    }
    $dt = date_create($date);
    if (!$dt instanceof DateTimeInterface) {
        return $date;
    }

    return bnDigits($dt->format('d/m/Y'));
}

function saveExamRoutineRow(PDO $db, array $data, int $id = 0): int
{
    if (!tableExists($db, 'exam_routine')) {
        throw new RuntimeException('পরীক্ষার রুটিন টেবিল পাওয়া যায়নি।');
    }

    $examId = (int) ($data['exam_id'] ?? 0);
    $sessionYear = trim((string) ($data['session_year'] ?? ''));
    $classId = (int) ($data['class_id'] ?? 0);
    $sectionId = (int) ($data['section_id'] ?? 0);
    $examDate = trim((string) ($data['exam_date'] ?? ''));
    $subjectName = trim((string) ($data['subject_name'] ?? ''));
    $classSubjectId = (int) ($data['class_subject_id'] ?? 0);
    $startTime = trim((string) ($data['start_time'] ?? ''));
    $endTime = trim((string) ($data['end_time'] ?? ''));
    $room = trim((string) ($data['room'] ?? ''));
    $fullMarks = trim((string) ($data['full_marks'] ?? ''));
    $note = trim((string) ($data['note'] ?? ''));
    $sortOrder = (int) ($data['sort_order'] ?? 0);

    if ($examId < 1) {
        throw new RuntimeException('পরীক্ষা নির্বাচন করুন।');
    }
    if ($sessionYear === '') {
        throw new RuntimeException('শিক্ষাবর্ষ দিন।');
    }
    if ($classId < 1) {
        throw new RuntimeException('শ্রেণী নির্বাচন করুন।');
    }
    if ($examDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate)) {
        throw new RuntimeException('সঠিক পরীক্ষার তারিখ দিন।');
    }
    if ($subjectName === '') {
        throw new RuntimeException('বিষয়ের নাম দিন।');
    }

    $params = [
        'exam_id' => $examId,
        'session_year' => $sessionYear,
        'class_id' => $classId,
        'section_id' => $sectionId,
        'exam_date' => $examDate,
        'subject_name' => $subjectName,
        'class_subject_id' => $classSubjectId > 0 ? $classSubjectId : null,
        'start_time' => $startTime !== '' ? $startTime : null,
        'end_time' => $endTime !== '' ? $endTime : null,
        'room' => $room !== '' ? $room : null,
        'full_marks' => $fullMarks !== '' ? $fullMarks : null,
        'note' => $note !== '' ? $note : null,
        'sort_order' => $sortOrder,
    ];

    if ($id > 0) {
        $params['id'] = $id;
        $db->prepare(
            'UPDATE exam_routine
             SET exam_id = :exam_id, session_year = :session_year, class_id = :class_id,
                 section_id = :section_id, exam_date = :exam_date, subject_name = :subject_name,
                 class_subject_id = :class_subject_id, start_time = :start_time, end_time = :end_time,
                 room = :room, full_marks = :full_marks, note = :note, sort_order = :sort_order
             WHERE id = :id'
        )->execute($params);

        return $id;
    }

    $db->prepare(
        'INSERT INTO exam_routine
         (exam_id, session_year, class_id, section_id, exam_date, subject_name, class_subject_id,
          start_time, end_time, room, full_marks, note, sort_order)
         VALUES
         (:exam_id, :session_year, :class_id, :section_id, :exam_date, :subject_name, :class_subject_id,
          :start_time, :end_time, :room, :full_marks, :note, :sort_order)'
    )->execute($params);

    return (int) $db->lastInsertId();
}

function deleteExamRoutineRow(PDO $db, int $id): void
{
    if (!tableExists($db, 'exam_routine') || $id < 1) {
        throw new RuntimeException('রুটিন এন্ট্রি পাওয়া যায়নি।');
    }
    $db->prepare('DELETE FROM exam_routine WHERE id = :id')->execute(['id' => $id]);
}

function clearExamRoutine(PDO $db, int $examId, string $sessionYear, int $classId, int $sectionId = -1): int
{
    if (!tableExists($db, 'exam_routine') || $examId < 1 || $sessionYear === '' || $classId < 1) {
        return 0;
    }
    $sql = 'DELETE FROM exam_routine
            WHERE exam_id = :exam_id AND session_year = :session_year AND class_id = :class_id';
    $params = [
        'exam_id' => $examId,
        'session_year' => $sessionYear,
        'class_id' => $classId,
    ];
    if ($sectionId >= 0) {
        $sql .= ' AND section_id = :section_id';
        $params['section_id'] = $sectionId;
    }
    $statement = $db->prepare($sql);
    $statement->execute($params);

    return $statement->rowCount();
}

function copyExamRoutineToSection(
    PDO $db,
    int $examId,
    string $sessionYear,
    int $fromClassId,
    int $fromSectionId,
    int $toClassId,
    int $toSectionId
): int {
    if ($fromClassId < 1 || $toClassId < 1 || $examId < 1 || $sessionYear === '') {
        throw new RuntimeException('কপি করার জন্য সঠিক শ্রেণী/পরীক্ষা নির্বাচন করুন।');
    }
    if ($fromClassId === $toClassId && $fromSectionId === $toSectionId) {
        throw new RuntimeException('একই শ্রেণী-শাখায় কপি করা যাবে না।');
    }

    $source = examRoutineRows($db, $examId, $sessionYear, $fromClassId, 0);
    $source = array_values(array_filter(
        $source,
        static fn (array $row): bool => (int) ($row['section_id'] ?? 0) === $fromSectionId
    ));
    if ($source === []) {
        throw new RuntimeException('কপি করার মতো কোনো রুটিন নেই।');
    }

    $copied = 0;
    foreach ($source as $row) {
        try {
            saveExamRoutineRow($db, [
                'exam_id' => $examId,
                'session_year' => $sessionYear,
                'class_id' => $toClassId,
                'section_id' => $toSectionId,
                'exam_date' => (string) ($row['exam_date'] ?? ''),
                'subject_name' => (string) ($row['subject_name'] ?? ''),
                'class_subject_id' => 0,
                'start_time' => (string) ($row['start_time'] ?? ''),
                'end_time' => (string) ($row['end_time'] ?? ''),
                'room' => (string) ($row['room'] ?? ''),
                'full_marks' => (string) ($row['full_marks'] ?? ''),
                'note' => (string) ($row['note'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ]);
            $copied++;
        } catch (Throwable) {
            // Skip duplicate subject/date slots.
        }
    }

    return $copied;
}

function studentMarksBySubject(PDO $db, string $roll, int $classId, int $sectionId, string $sessionYear, int $examId, bool $requireFinal = true): array
{
    if (!tableExists($db, 'student_marks')) {
        return [];
    }
    $rows = fetchRows(
        $db,
        'SELECT * FROM student_marks
         WHERE cls_roll = :roll AND class_id = :class_id AND section_id = :section_id
           AND session_year = :session_year AND exam_id = :exam_id',
        [
            'roll' => $roll,
            'class_id' => $classId,
            'section_id' => $sectionId,
            'session_year' => $sessionYear,
            'exam_id' => $examId,
        ]
    );
    if ($rows === [] && $sessionYear !== '') {
        $rows = fetchRows(
            $db,
            'SELECT * FROM student_marks
             WHERE cls_roll = :roll AND class_id = :class_id AND section_id = :section_id
               AND (session_year = \'\' OR session_year IS NULL) AND exam_id = :exam_id',
            [
                'roll' => $roll,
                'class_id' => $classId,
                'section_id' => $sectionId,
                'exam_id' => $examId,
            ]
        );
    }
    // Hide any subject still in draft (temporarily saved, not Final Submitted)
    // from public / read-only marksheets.
    $nonFinal = $requireFinal ? nonFinalSubjectIds($db, $classId, $sectionId, $sessionYear, $examId) : [];
    $map = [];
    foreach ($rows as $row) {
        $subjectId = (int) $row['class_subject_id'];
        if ($requireFinal && isset($nonFinal[$subjectId])) {
            continue;
        }
        $map[$subjectId] = $row;
    }

    return $map;
}

function studentExamRemark(PDO $db, string $roll, int $classId, int $sectionId, string $sessionYear, int $examId): string
{
    if (!tableExists($db, 'student_exam_remarks')) {
        return '';
    }
    $rows = fetchRows(
        $db,
        'SELECT remarks FROM student_exam_remarks
         WHERE cls_roll = :roll AND class_id = :class_id AND section_id = :section_id
           AND session_year = :session_year AND exam_id = :exam_id
         LIMIT 1',
        [
            'roll' => $roll,
            'class_id' => $classId,
            'section_id' => $sectionId,
            'session_year' => $sessionYear,
            'exam_id' => $examId,
        ]
    );

    return (string) ($rows[0]['remarks'] ?? '');
}

function studentResultRows(PDO $db, string $roll, int $classId, int $sectionId, string $sessionYear, int $examId): array
{
    $subjects = classSubjects($db, $classId, $sectionId);
    $marks = studentMarksBySubject($db, $roll, $classId, $sectionId, $sessionYear, $examId);
    $rows = [];
    $grand = 0.0;
    foreach ($subjects as $subject) {
        $mark = $marks[(int) $subject['id']] ?? null;
        $mcq = $mark['mcq'] ?? null;
        $cq = $mark['cq'] ?? null;
        $practical = $mark['practical'] ?? null;
        if ($mcq === null && $cq === null && $practical === null) {
            continue;
        }
        $total = (float) $mcq + (float) $cq + (float) $practical;
        $grand += $total;
        $full = (float) ($subject['full_marks'] ?? 100);
        $rows[] = [
            'subject' => $subject,
            'mcq' => $mcq,
            'cq' => $cq,
            'practical' => $practical,
            'total' => $total,
            'grade' => letterGrade($total, $full > 0 ? $full : 100),
        ];
    }

    return ['rows' => $rows, 'total' => $grand];
}

function schoolTeachers(PDO $db): array
{
    if (!tableExists($db, 'teacher_info')) {
        return [];
    }

    return fetchRows($db, 'SELECT teacher_mobile, teacher_name, teacher_subject FROM teacher_info ORDER BY teacher_name');
}

function classLabel(PDO $db, $classId): string
{
    if ($classId === null || $classId === '') {
        return '';
    }
    $row = fetchRows($db, 'SELECT class_name_ban, class_name FROM cls WHERE id = :id LIMIT 1', ['id' => (int) $classId]);

    return $row[0]['class_name_ban'] ?? $row[0]['class_name'] ?? (string) $classId;
}

function sectionLabel(PDO $db, $sectionId): string
{
    if ($sectionId === null || $sectionId === '') {
        return '';
    }
    $row = fetchRows($db, 'SELECT section_name_ban, section_name FROM sections WHERE id = :id LIMIT 1', ['id' => (int) $sectionId]);

    return $row[0]['section_name_ban'] ?? $row[0]['section_name'] ?? (string) $sectionId;
}

function classSubjectMap(PDO $db, int $classId, int $sectionId): array
{
    $normalized = classSubjects($db, $classId, $sectionId);
    if ($normalized !== []) {
        $map = [];
        foreach ($normalized as $row) {
            $label = trim((string) ($row['subject_name_ban'] ?: $row['subject_name']));
            if ($label !== '') {
                $map[(int) $row['id']] = $label;
            }
        }

        return $map;
    }

    if (!tableExists($db, 'subjects')) {
        return [];
    }
    $rows = fetchRows($db, 'SELECT * FROM subjects WHERE cls_id = :c AND sections_id = :s LIMIT 1', ['c' => $classId, 's' => $sectionId]);
    if ($rows === []) {
        $rows = fetchRows($db, 'SELECT * FROM subjects WHERE cls_id = :c LIMIT 1', ['c' => $classId]);
    }
    if ($rows === []) {
        return [];
    }
    $map = [];
    for ($i = 1; $i <= 14; $i++) {
        $key = 's' . $i;
        $name = trim((string) ($rows[0][$key] ?? ''));
        if ($name !== '') {
            $map[$key] = $name;
        }
    }

    return $map;
}

function subjectsRow(PDO $db, int $classId, int $sectionId): ?array
{
    if (!tableExists($db, 'subjects')) {
        return null;
    }
    $rows = fetchRows($db, 'SELECT * FROM subjects WHERE cls_id = :c AND sections_id = :s LIMIT 1', ['c' => $classId, 's' => $sectionId]);
    if ($rows === []) {
        $rows = fetchRows($db, 'SELECT * FROM subjects WHERE cls_id = :c LIMIT 1', ['c' => $classId]);
    }

    return $rows[0] ?? null;
}

/**
 * The class a student moves up to, taken from the managed class order instead
 * of a hardcoded map, so newly added classes join the promotion chain.
 */
function nextClassId(PDO $db, $currentClassId): ?int
{
    $current = (int) $currentClassId;
    if ($current === 0) {
        return null;
    }

    $found = false;
    foreach (schoolClasses($db, true) as $class) {
        if ($found) {
            return (int) $class['id'];
        }
        if ((int) $class['id'] === $current) {
            $found = true;
        }
    }

    return null;
}

function studentDefaultPhotoFile(): string
{
    return 'image/student.png';
}

function studentPhotoUrl(?string $file): string
{
    $file = trim((string) $file);
    if ($file === '') {
        return publicSiteUrl(studentDefaultPhotoFile());
    }
    if (str_starts_with($file, 'http') || str_starts_with($file, '/')) {
        return $file;
    }
    $basename = publicMediaBasename($file);
    if ($basename === '' || !is_file(publicSitePath('student_pic/' . $basename))) {
        return publicSiteUrl(studentDefaultPhotoFile());
    }

    return publicSiteUrl('student_pic/' . $basename);
}

function studentStatusAdmitted(): int
{
    return 1;
}

function studentStatusSscCandidate(): int
{
    return 3;
}

/** Stopped studies / left school — kept in history, not in active class lists. */
function studentStatusLeft(): int
{
    return 4;
}

function admissionStatusLabel($status): array
{
    $code = (int) $status;
    return match ($code) {
        1 => ['Admitted', 'success'],
        2 => ['Rejected', 'danger'],
        3 => ['SSC candidate batch', 'info'],
        4 => ['Left / Dropout', 'secondary'],
        default => ['Pending', 'warning'],
    };
}
function countActiveStudentsInClass(PDO $db, int $classId, string $sessionYear, string $sectionId = ''): int
{
    if ($classId < 1 || $sessionYear === '') {
        return 0;
    }
    $sql = 'SELECT COUNT(*) AS total FROM students_info
            WHERE status = :status AND cls = :cls AND session_year = :session_year';
    $params = [
        'status' => studentStatusAdmitted(),
        'cls' => $classId,
        'session_year' => $sessionYear,
    ];
    if ($sectionId !== '') {
        $sql .= ' AND sections = :sections';
        $params['sections'] = $sectionId;
    }
    $rows = fetchRows($db, $sql, $params);

    return (int) ($rows[0]['total'] ?? 0);
}

function sscCandidateBatchYears(PDO $db): array
{
    if (!tableExists($db, 'students_info') || !columnExists($db, 'students_info', 'ssc_batch_year')) {
        return [];
    }
    $rows = fetchRows(
        $db,
        'SELECT DISTINCT ssc_batch_year AS year FROM students_info
         WHERE status = :status AND ssc_batch_year <> \'\'
         ORDER BY CAST(ssc_batch_year AS UNSIGNED) DESC, ssc_batch_year DESC',
        ['status' => studentStatusSscCandidate()]
    );
    $years = [];
    foreach ($rows as $row) {
        $year = trim((string) ($row['year'] ?? ''));
        if ($year !== '') {
            $years[] = $year;
        }
    }

    return $years;
}

function sscStudentTypeOptions(): array
{
    return [
        'regular' => 'Regular / নিয়মিত',
        'irregular' => 'Irregular / অনিয়মিত',
    ];
}

function normalizeSscStudentType(string $raw): string
{
    $key = strtolower(trim($raw));
    return array_key_exists($key, sscStudentTypeOptions()) ? $key : '';
}

/** @return array<string, string> Bangla SSC group => English label */
function sscGroupEnglishMap(): array
{
    return [
        'বিজ্ঞান' => 'Science',
        'মানবিক' => 'Humanities',
        'ব্যবসায় শিক্ষা' => 'Business Studies',
    ];
}

function sscGroupEnglish(string $bangla): string
{
    // য়/ড়/ঢ় may be stored precomposed or as base letter + nukta.
    $fold = static fn (string $s): string => preg_replace('/\s+/u', ' ', trim(str_replace(
        ["\u{09DF}", "\u{09DC}", "\u{09DD}"],
        ["\u{09AF}\u{09BC}", "\u{09A1}\u{09BC}", "\u{09A2}\u{09BC}"],
        $s
    ))) ?? '';
    $key = $fold($bangla);
    if ($key === '') {
        return '';
    }
    foreach (sscGroupEnglishMap() as $bn => $en) {
        if ($fold($bn) === $key) {
            return $en;
        }
    }

    return '';
}

function sscStudentTypeLabel(string $raw, string $lang = 'bn'): string
{
    $key = normalizeSscStudentType($raw);
    if ($key === '') {
        return '';
    }
    if (certificateLanguage($lang) === 'en') {
        return $key === 'irregular' ? 'Irregular' : 'Regular';
    }

    return $key === 'irregular' ? 'অনিয়মিত' : 'নিয়মিত';
}

function sscCandidateStudents(PDO $db, string $batchYear = '', string $search = '', string $rollFilter = ''): array
{
    if (!tableExists($db, 'students_info')) {
        return [];
    }
    $sql = 'SELECT s.*, c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name
            FROM students_info s'
        . studentsListJoinSql() . '
            WHERE s.status = :status';
    $params = ['status' => studentStatusSscCandidate()];
    if ($batchYear !== '' && columnExists($db, 'students_info', 'ssc_batch_year')) {
        $sql .= ' AND s.ssc_batch_year = :batch';
        $params['batch'] = $batchYear;
    }
    if ($search !== '') {
        $sql .= ' AND (s.name_ban LIKE :q_name_ban OR s.name_eng LIKE :q_name_eng OR s.mobile LIKE :q_mobile
                  OR s.cls_roll LIKE :q_roll OR s.ssc_roll_no LIKE :q_ssc_roll OR s.ssc_reg_no LIKE :q_ssc_reg)';
        $like = '%' . $search . '%';
        $params['q_name_ban'] = $like;
        $params['q_name_eng'] = $like;
        $params['q_mobile'] = $like;
        $params['q_roll'] = $like;
        $params['q_ssc_roll'] = $like;
        $params['q_ssc_reg'] = $like;
    }
    if ($rollFilter !== '') {
        $rollValues = studentIdCardRollFilterValues($rollFilter);
        if ($rollValues !== []) {
            $clsPlaceholders = [];
            $sscPlaceholders = [];
            foreach ($rollValues as $index => $value) {
                $clsKey = 'cls_roll_' . $index;
                $sscKey = 'ssc_roll_' . $index;
                $clsPlaceholders[] = ':' . $clsKey;
                $sscPlaceholders[] = ':' . $sscKey;
                $params[$clsKey] = $value;
                $params[$sscKey] = $value;
            }
            $sql .= ' AND (s.cls_roll IN (' . implode(', ', $clsPlaceholders) . ') OR s.ssc_roll_no IN (' . implode(', ', $sscPlaceholders) . '))';
        }
    }
    $sql .= ' ORDER BY CAST(NULLIF(s.ssc_roll_no, \'\') AS UNSIGNED) ASC, s.ssc_group ASC, '
        . 'CAST(s.ssc_batch_year AS UNSIGNED) DESC, ' . studentsListOrderSql();

    return fetchRows($db, $sql, $params);
}

/** True when another admitted student already holds this roll in class/session/section. */
function activeStudentRollTaken(
    PDO $db,
    int $classId,
    string $sessionYear,
    string $sectionId,
    string $roll,
    int $excludeStudentId = 0
): bool {
    if ($classId < 1 || $sessionYear === '' || $roll === '') {
        return false;
    }
    $rollValues = studentIdCardRollFilterValues($roll);
    if ($rollValues === []) {
        $rollValues = [trim($roll)];
    }
    $placeholders = [];
    $params = [
        'status' => studentStatusAdmitted(),
        'cls' => $classId,
        'session_year' => $sessionYear,
    ];
    foreach ($rollValues as $index => $value) {
        $key = 'roll_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $value;
    }
    $sql = 'SELECT id FROM students_info
            WHERE status = :status AND cls = :cls AND session_year = :session_year
              AND CAST(cls_roll AS CHAR) IN (' . implode(', ', $placeholders) . ')';
    if ($sectionId !== '') {
        $sql .= ' AND sections = :sections';
        $params['sections'] = $sectionId;
    }
    if ($excludeStudentId > 0) {
        $sql .= ' AND id <> :exclude';
        $params['exclude'] = $excludeStudentId;
    }
    $sql .= ' LIMIT 1';

    return fetchRows($db, $sql, $params) !== [];
}

/** Block placing an admitted student on a roll already used by another active student. */
function assertActiveStudentRollAvailable(
    PDO $db,
    int $classId,
    string $sessionYear,
    string $sectionId,
    string $roll,
    int $excludeStudentId = 0
): void {
    $roll = trim($roll);
    if ($classId < 1 || $sessionYear === '' || $roll === '') {
        return;
    }
    if (activeStudentRollTaken($db, $classId, $sessionYear, $sectionId, $roll, $excludeStudentId)) {
        throw new RuntimeException(
            'এই শ্রেণি/শাখা/সেশনে রোল ' . $roll . ' ইতিমধ্যে একজন সক্রিয় (Admitted) শিক্ষার্থীর আছে। অন্য রোল দিন।'
        );
    }
}

function upsertStudentEnrollment(PDO $db, array $data): void
{
    if (!tableExists($db, 'student_enrollments')) {
        return;
    }
    $studentId = (int) ($data['student_id'] ?? 0);
    $session = trim((string) ($data['session_year'] ?? ''));
    $classId = (int) ($data['class_id'] ?? 0);
    if ($studentId < 1 || $session === '' || $classId < 1) {
        return;
    }
    $sectionId = (int) ($data['section_id'] ?? 0);
    $roll = trim((string) ($data['cls_roll'] ?? ''));
    $status = trim((string) ($data['enrollment_status'] ?? 'active')) ?: 'active';
    $source = trim((string) ($data['source'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));
    $endedOn = $data['ended_on'] ?? null;
    $startedOn = $data['started_on'] ?? date('Y-m-d');

    $existing = fetchRows(
        $db,
        'SELECT id FROM student_enrollments
         WHERE student_id = :sid AND session_year = :session AND class_id = :cls
           AND section_id = :sec AND cls_roll = :roll
         LIMIT 1',
        [
            'sid' => $studentId,
            'session' => $session,
            'cls' => $classId,
            'sec' => $sectionId,
            'roll' => $roll,
        ]
    );
    if ($existing !== []) {
        $db->prepare(
            'UPDATE student_enrollments
             SET enrollment_status = :status,
                 source = CASE WHEN source = \'\' THEN :source ELSE source END,
                 notes = CASE WHEN :notes = \'\' THEN notes ELSE :notes2 END,
                 ended_on = COALESCE(:ended_on, ended_on),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'source' => $source,
            'notes' => $notes,
            'notes2' => $notes,
            'ended_on' => $endedOn,
            'id' => (int) $existing[0]['id'],
        ]);

        return;
    }

    $db->prepare(
        'INSERT INTO student_enrollments
            (student_id, session_year, class_id, section_id, cls_roll, enrollment_status, started_on, ended_on, source, notes)
         VALUES
            (:sid, :session, :cls, :sec, :roll, :status, :started_on, :ended_on, :source, :notes)'
    )->execute([
        'sid' => $studentId,
        'session' => $session,
        'cls' => $classId,
        'sec' => $sectionId,
        'roll' => $roll,
        'status' => $status,
        'started_on' => $startedOn,
        'ended_on' => $endedOn,
        'source' => $source,
        'notes' => $notes,
    ]);
}

function completeStudentEnrollmentPeriod(
    PDO $db,
    int $studentId,
    string $sessionYear,
    int $classId,
    int $sectionId,
    string $roll,
    string $status = 'completed',
    string $notes = ''
): void {
    upsertStudentEnrollment($db, [
        'student_id' => $studentId,
        'session_year' => $sessionYear,
        'class_id' => $classId,
        'section_id' => $sectionId,
        'cls_roll' => $roll,
        'enrollment_status' => $status,
        'source' => 'promotion',
        'notes' => $notes,
        'ended_on' => date('Y-m-d'),
    ]);
}

function openStudentEnrollmentPeriod(
    PDO $db,
    int $studentId,
    string $sessionYear,
    int $classId,
    int $sectionId,
    string $roll,
    string $source = 'promotion'
): void {
    upsertStudentEnrollment($db, [
        'student_id' => $studentId,
        'session_year' => $sessionYear,
        'class_id' => $classId,
        'section_id' => $sectionId,
        'cls_roll' => $roll,
        'enrollment_status' => 'active',
        'source' => $source,
        'started_on' => date('Y-m-d'),
        'ended_on' => null,
    ]);
}

function recordStudentClassPromotionEnrollments(
    PDO $db,
    array $from,
    array $to,
    string $mode = 'class'
): void {
    $studentId = (int) ($from['student_id'] ?? 0);
    if ($studentId < 1) {
        return;
    }
    if ($mode === 'ssc_return') {
        openStudentEnrollmentPeriod(
            $db,
            $studentId,
            trim((string) ($to['session_year'] ?? '')),
            (int) ($to['cls'] ?? 0),
            (int) ($to['sections'] ?? 0),
            trim((string) ($to['cls_roll'] ?? '')),
            'ssc_return'
        );

        return;
    }
    completeStudentEnrollmentPeriod(
        $db,
        $studentId,
        trim((string) ($from['session_year'] ?? '')),
        (int) ($from['cls'] ?? 0),
        (int) ($from['sections'] ?? 0),
        trim((string) ($from['cls_roll'] ?? '')),
        $mode === 'ssc' ? 'ssc_batch' : 'completed',
        $mode === 'ssc' ? 'Moved to SSC candidate batch' : 'Promoted to next class'
    );
    if ($mode === 'ssc') {
        return;
    }
    openStudentEnrollmentPeriod(
        $db,
        $studentId,
        trim((string) ($to['session_year'] ?? '')),
        (int) ($to['cls'] ?? 0),
        (int) ($to['sections'] ?? 0),
        trim((string) ($to['cls_roll'] ?? '')),
        'promotion'
    );
}

function markStudentLeftSchool(PDO $db, int $studentId, string $notes = ''): bool
{
    $rows = fetchRows(
        $db,
        'SELECT id, cls, sections, session_year, cls_roll, status FROM students_info WHERE id = :id LIMIT 1',
        ['id' => $studentId]
    );
    if ($rows === []) {
        return false;
    }
    $row = $rows[0];
    if ((int) ($row['status'] ?? 0) !== studentStatusAdmitted()) {
        return false;
    }
    $db->prepare('UPDATE students_info SET status = :status WHERE id = :id')->execute([
        'status' => studentStatusLeft(),
        'id' => $studentId,
    ]);
    completeStudentEnrollmentPeriod(
        $db,
        $studentId,
        trim((string) ($row['session_year'] ?? '')),
        (int) ($row['cls'] ?? 0),
        (int) ($row['sections'] ?? 0),
        trim((string) ($row['cls_roll'] ?? '')),
        'left',
        $notes !== '' ? $notes : 'Left school / stopped studies'
    );

    return true;
}

function backfillStudentEnrollments(PDO $db): void
{
    if (!tableExists($db, 'student_enrollments') || !tableExists($db, 'students_info')) {
        return;
    }

    $students = fetchRows(
        $db,
        'SELECT id, cls, sections, session_year, cls_roll, status
         FROM students_info
         WHERE session_year IS NOT NULL AND TRIM(session_year) <> \'\' AND cls IS NOT NULL AND cls > 0'
    );
    foreach ($students as $student) {
        $status = (int) ($student['status'] ?? 0);
        $enrollStatus = match ($status) {
            3 => 'ssc_batch',
            4 => 'left',
            1 => 'active',
            default => 'active',
        };
        upsertStudentEnrollment($db, [
            'student_id' => (int) $student['id'],
            'session_year' => trim((string) $student['session_year']),
            'class_id' => (int) $student['cls'],
            'section_id' => (int) $student['sections'],
            'cls_roll' => trim((string) ($student['cls_roll'] ?? '')),
            'enrollment_status' => $enrollStatus,
            'source' => 'backfill_current',
            'notes' => '',
            'ended_on' => in_array($enrollStatus, ['ssc_batch', 'left', 'completed'], true) ? date('Y-m-d') : null,
        ]);
    }

    if (!tableExists($db, 'student_promotions')) {
        return;
    }
    $promos = fetchRows(
        $db,
        'SELECT student_id, from_class, from_section, from_session, from_roll, remarks, created_at
         FROM student_promotions
         WHERE from_session IS NOT NULL AND TRIM(from_session) <> \'\' AND from_class IS NOT NULL AND from_class > 0
         ORDER BY id ASC'
    );
    foreach ($promos as $promo) {
        upsertStudentEnrollment($db, [
            'student_id' => (int) $promo['student_id'],
            'session_year' => trim((string) $promo['from_session']),
            'class_id' => (int) $promo['from_class'],
            'section_id' => (int) $promo['from_section'],
            'cls_roll' => trim((string) ($promo['from_roll'] ?? '')),
            'enrollment_status' => 'completed',
            'source' => 'backfill_promotion',
            'notes' => trim((string) ($promo['remarks'] ?? '')),
            'ended_on' => substr((string) ($promo['created_at'] ?? date('Y-m-d')), 0, 10),
        ]);
    }
}

function studentAcademicHistory(PDO $db, int $studentId): array
{
    if ($studentId < 1 || !tableExists($db, 'student_enrollments')) {
        return [];
    }

    return fetchRows(
        $db,
        'SELECT e.*, c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name
         FROM student_enrollments e
         LEFT JOIN cls c ON e.class_id = c.id
         LEFT JOIN sections sec ON e.section_id = sec.id
         WHERE e.student_id = :id
         ORDER BY CAST(e.session_year AS UNSIGNED) DESC, e.id DESC',
        ['id' => $studentId]
    );
}

function studentMarksHistoryBySession(PDO $db, int $studentId): array
{
    if ($studentId < 1 || !tableExists($db, 'student_marks')) {
        return [];
    }

    return fetchRows(
        $db,
        'SELECT session_year, class_id, COUNT(*) AS paper_count,
                SUM(COALESCE(mcq,0) + COALESCE(cq,0) + COALESCE(practical,0)) AS total_marks
         FROM student_marks
         WHERE student_id = :id
         GROUP BY session_year, class_id
         ORDER BY CAST(session_year AS UNSIGNED) DESC',
        ['id' => $studentId]
    );
}

function uniqueAdmissionToken(PDO $db): string
{
    $attempts = 0;
    do {
        $token = (string) random_int(1000000000, 2147483647);
        $attempts++;
        if ($attempts > 40) {
            throw new RuntimeException('Could not generate a unique admission token.');
        }
    } while (admissionTokenIsTaken($db, $token));

    return $token;
}

function admissionTokenStatusColumn(PDO $db): string
{
    if (columnExists($db, 'token', 'token_status')) {
        return 'token_status';
    }
    if (columnExists($db, 'token', 'status')) {
        return 'status';
    }

    return 'token_status';
}

function admissionTokenIsTaken(PDO $db, string $token): bool
{
    if ($token === '') {
        return true;
    }
    if (tableExists($db, 'token') && columnExists($db, 'token', 'token')) {
        $inPool = fetchRows($db, 'SELECT id FROM token WHERE token = :t LIMIT 1', ['t' => $token]);
        if ($inPool !== []) {
            return true;
        }
    }
    if (tableExists($db, 'students_info') && columnExists($db, 'students_info', 'admission_token')) {
        $inStudent = fetchRows($db, 'SELECT id FROM students_info WHERE admission_token = :t LIMIT 1', ['t' => $token]);
        return $inStudent !== [];
    }

    return false;
}

function insertUnusedAdmissionToken(PDO $db, string $token): void
{
    $statusCol = admissionTokenStatusColumn($db);
    $quotedStatus = '`' . str_replace('`', '``', $statusCol) . '`';
    if (columnExists($db, 'token', 'token_creation_time')) {
        $db->prepare("INSERT INTO token (token, {$quotedStatus}, token_creation_time) VALUES (:token, 0, CURRENT_TIMESTAMP)")
            ->execute(['token' => $token]);
        return;
    }
    $db->prepare("INSERT INTO token (token, {$quotedStatus}) VALUES (:token, 0)")
        ->execute(['token' => $token]);
}

function generateUnusedAdmissionTokens(PDO $db, int $count): array
{
    if (!tableExists($db, 'token')) {
        throw new RuntimeException('Admission token table is missing.');
    }
    $count = max(1, min(50, $count));
    $created = [];
    for ($i = 0; $i < $count; $i++) {
        $token = uniqueAdmissionToken($db);
        insertUnusedAdmissionToken($db, $token);
        $created[] = $token;
    }

    return $created;
}

function addCustomAdmissionToken(PDO $db, string $token): string
{
    if (!tableExists($db, 'token')) {
        throw new RuntimeException('Admission token table is missing.');
    }
    $token = preg_replace('/\s+/', '', $token) ?? '';
    if ($token === '') {
        throw new RuntimeException('Token is required.');
    }
    if (!preg_match('/^[0-9A-Za-z-]{4,32}$/', $token)) {
        throw new RuntimeException('Use 4–32 letters, numbers or hyphens.');
    }
    if (admissionTokenIsTaken($db, $token)) {
        throw new RuntimeException('This token already exists or has been used.');
    }
    insertUnusedAdmissionToken($db, $token);

    return $token;
}

function deleteUnusedAdmissionToken(PDO $db, int $id): void
{
    if ($id < 1 || !tableExists($db, 'token')) {
        throw new RuntimeException('Token not found.');
    }
    $statusCol = admissionTokenStatusColumn($db);
    $quotedStatus = '`' . str_replace('`', '``', $statusCol) . '`';
    $rows = fetchRows($db, "SELECT id, token, {$quotedStatus} AS token_status FROM token WHERE id = :id LIMIT 1", ['id' => $id]);
    $row = $rows[0] ?? null;
    if (!is_array($row)) {
        throw new RuntimeException('Token not found.');
    }
    if ((int) ($row['token_status'] ?? 0) === 1) {
        throw new RuntimeException('Used tokens cannot be deleted.');
    }
    $value = (string) ($row['token'] ?? '');
    if ($value !== '' && tableExists($db, 'students_info') && columnExists($db, 'students_info', 'admission_token')) {
        $used = fetchRows($db, 'SELECT id FROM students_info WHERE admission_token = :t LIMIT 1', ['t' => $value]);
        if ($used !== []) {
            throw new RuntimeException('This token is already attached to an application.');
        }
    }
    $db->prepare('DELETE FROM token WHERE id = :id LIMIT 1')->execute(['id' => $id]);
}

function letterGrade(float $obtained, float $full = 100): string
{
    if ($full <= 0) {
        return 'N/A';
    }
    $percent = ($obtained / $full) * 100;
    return match (true) {
        $percent >= 80 => 'A+',
        $percent >= 70 => 'A',
        $percent >= 60 => 'A-',
        $percent >= 50 => 'B',
        $percent >= 40 => 'C',
        $percent >= 33 => 'D',
        default => 'F',
    };
}

function letterFromGpa(float $gpa): string
{
    return match (true) {
        $gpa >= 5.0 => 'A+',
        $gpa >= 4.0 => 'A',
        $gpa >= 3.5 => 'A-',
        $gpa >= 3.0 => 'B',
        $gpa >= 2.0 => 'C',
        $gpa >= 1.0 => 'D',
        default => 'F',
    };
}

function studentResultSheet(
    PDO $db,
    string $roll,
    int $classId,
    int $sectionId,
    string $sessionYear,
    int $examId
): array {
    $sheet = studentResultRows($db, $roll, $classId, $sectionId, $sessionYear, $examId);
    if ($sheet['rows'] === [] && $sectionId !== 0) {
        $sheet = studentResultRows($db, $roll, $classId, 0, $sessionYear, $examId);
    }

    $gradePoints = [];
    $failed = false;
    $fullTotal = 0.0;
    foreach ($sheet['rows'] as $paper) {
        $optional = (int) ($paper['subject']['is_optional'] ?? 0) === 1;
        $fullTotal += (float) ($paper['subject']['full_marks'] ?? 100);
        if ($optional) {
            continue;
        }
        $gradePoints[] = gradePoint((string) $paper['grade']);
        if ((string) $paper['grade'] === 'F') {
            $failed = true;
        }
    }
    $gpa = $gradePoints === [] ? 0.0 : round(array_sum($gradePoints) / count($gradePoints), 2);
    if ($failed) {
        $gpa = 0.0;
    }

    $sheet['gpa'] = $gpa;
    $sheet['failed'] = $failed;
    $sheet['letter'] = $failed || $gradePoints === [] ? 'F' : letterFromGpa($gpa);
    $sheet['full_total'] = $fullTotal;
    $sheet['remarks'] = studentExamRemark($db, $roll, $classId, $sectionId, $sessionYear, $examId);
    if ($sheet['remarks'] === '' && $sectionId !== 0) {
        $sheet['remarks'] = studentExamRemark($db, $roll, $classId, 0, $sessionYear, $examId);
    }

    return $sheet;
}

function classExamResultSummaries(
    PDO $db,
    int $classId,
    int $sectionId,
    string $sessionYear,
    int $examId,
    string $search = ''
): array {
    if ($classId < 1 || $examId < 1 || $sessionYear === '') {
        return [];
    }

    $sql = 'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.student_pic, s.fname_ban, s.mname_ban, s.sections, s.session_year, s.birth_date
            FROM students_info s'
            . studentsListJoinSql() . '
            WHERE s.status = 1 AND s.cls = :cls AND s.session_year = :session_year';
    $params = ['cls' => $classId, 'session_year' => $sessionYear];
    if ($sectionId > 0) {
        $sql .= ' AND s.sections = :sections';
        $params['sections'] = (string) $sectionId;
    }
    $search = trim($search);
    if ($search !== '') {
        $sql .= ' AND (s.cls_roll LIKE :q_roll OR s.name_ban LIKE :q_name_ban OR s.name_eng LIKE :q_name_eng)';
        $like = '%' . $search . '%';
        $params['q_roll'] = $like;
        $params['q_name_ban'] = $like;
        $params['q_name_eng'] = $like;
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql();
    $students = fetchRows($db, $sql, $params);

    $results = [];
    foreach ($students as $student) {
        $stuSection = $sectionId > 0 ? $sectionId : (int) ($student['sections'] ?? 0);
        $sheet = studentResultSheet(
            $db,
            (string) $student['cls_roll'],
            $classId,
            $stuSection,
            $sessionYear,
            $examId
        );
        if ($sheet['rows'] === []) {
            continue;
        }
        $results[] = [
            'student' => $student,
            'sheet' => $sheet,
        ];
    }

    usort(
        $results,
        static function (array $left, array $right): int {
            $gpa = $right['sheet']['gpa'] <=> $left['sheet']['gpa'];
            if ($gpa !== 0) {
                return $gpa;
            }

            return $right['sheet']['total'] <=> $left['sheet']['total'];
        }
    );

    $rank = 1;
    foreach ($results as $index => $row) {
        $results[$index]['rank'] = $rank;
        $next = $results[$index + 1] ?? null;
        if ($next === null) {
            break;
        }
        $tie = abs((float) $next['sheet']['gpa'] - (float) $row['sheet']['gpa']) < 0.001
            && (int) round((float) $next['sheet']['total']) === (int) round((float) $row['sheet']['total']);
        if (!$tie) {
            $rank = $index + 2;
        }
    }

    return $results;
}

function sortResultSummariesByRoll(array $summaries): array
{
    usort(
        $summaries,
        static function (array $left, array $right): int {
            $leftRoll = asciiDigits((string) ($left['student']['cls_roll'] ?? ''));
            $rightRoll = asciiDigits((string) ($right['student']['cls_roll'] ?? ''));
            $rollOrder = (int) $leftRoll <=> (int) $rightRoll;
            if ($rollOrder !== 0) {
                return $rollOrder;
            }

            return (int) ($left['student']['id'] ?? 0) <=> (int) ($right['student']['id'] ?? 0);
        }
    );

    return $summaries;
}

function gradePoint(string $grade): float
{
    return match ($grade) {
        'A+' => 5.0,
        'A' => 4.0,
        'A-' => 3.5,
        'B' => 3.0,
        'C' => 2.0,
        'D' => 1.0,
        default => 0.0,
    };
}

function weekDays(): array
{
    return [
        0 => 'রবিবার / Sunday',
        1 => 'সোমবার / Monday',
        2 => 'মঙ্গলবার / Tuesday',
        3 => 'বুধবার / Wednesday',
        4 => 'বৃহস্পতিবার / Thursday',
    ];
}

function defaultPeriods(): array
{
    return [
        1 => '১০:০১–১০:৫০',
        2 => '১০:৫১–১১:৩৫',
        3 => '১১:৩৬–১২:২০',
        4 => '১২:২১–০১:০৫',
        5 => '০১:৫০–০২:৩০',
        6 => '০২:৩১–০৩:১০',
        7 => '০৩:১১–০৩:৫০',
    ];
}

function defaultPeriodLabels(): array
{
    return [
        1 => '১ম ঘন্টা',
        2 => '২য় ঘন্টা',
        3 => '৩য় ঘন্টা',
        4 => '৪র্থ ঘন্টা',
        5 => '৫ম ঘন্টা',
        6 => '৬ষ্ঠ ঘন্টা',
        7 => '৭ম ঘন্টা',
        8 => '৮ম ঘন্টা',
        9 => '৯ম ঘন্টা',
        10 => '১০ম ঘন্টা',
    ];
}

function periodDisplayLabel(int $periodNo, ?string $stored = null): string
{
    $stored = trim((string) $stored);
    if ($stored !== '') {
        return $stored;
    }

    return defaultPeriodLabels()[$periodNo] ?? ('ঘন্টা ' . bnDigits((string) $periodNo));
}

function weekDayShortName(int $day): string
{
    return match ($day) {
        6 => 'শনি',
        0 => 'রবি',
        1 => 'সোম',
        2 => 'মঙ্গল',
        3 => 'বুধ',
        4 => 'বৃহস্পতি',
        5 => 'শুক্র',
        default => '',
    };
}

function weekDayBangla(int $day): string
{
    $label = weekDays()[$day] ?? '';
    $parts = explode(' / ', $label);

    return $parts[0] !== '' ? $parts[0] : $label;
}

function schoolPeriodMeta(PDO $db): array
{
    if (tableExists($db, 'class_periods')) {
        $hasLabel = columnExists($db, 'class_periods', 'period_label');
        $sql = $hasLabel
            ? 'SELECT period_no, period_time, period_label FROM class_periods WHERE is_active = 1 ORDER BY sort_order ASC, period_no ASC'
            : 'SELECT period_no, period_time FROM class_periods WHERE is_active = 1 ORDER BY sort_order ASC, period_no ASC';
        $rows = fetchRows($db, $sql);
        if ($rows !== []) {
            $map = [];
            foreach ($rows as $row) {
                $no = (int) $row['period_no'];
                $map[$no] = [
                    'no' => $no,
                    'time' => (string) $row['period_time'],
                    'label' => periodDisplayLabel($no, (string) ($row['period_label'] ?? '')),
                ];
            }

            return $map;
        }
    }

    $map = [];
    foreach (defaultPeriods() as $periodNo => $time) {
        $map[$periodNo] = [
            'no' => $periodNo,
            'time' => $time,
            'label' => periodDisplayLabel($periodNo),
        ];
    }

    return $map;
}

function routineSettings(PDO $db): array
{
    $defaults = [
        'assembly_label' => 'সমাবেশ',
        'assembly_time' => '৯:৪০–১০:০০',
        'break_label' => 'বিরতি',
        'break_time' => '১:০৬–০১:৫০',
        'session_label' => '',
        'footer_note' => '',
        'is_published' => 1,
    ];
    if (!tableExists($db, 'routine_settings')) {
        return $defaults;
    }
    $row = $db->query('SELECT * FROM routine_settings ORDER BY id ASC LIMIT 1')->fetch() ?: [];

    return array_merge($defaults, array_filter(
        $row,
        static fn ($value, $key): bool => $key === 'is_published' || ($value !== null && $value !== ''),
        ARRAY_FILTER_USE_BOTH
    ));
}

function saveRoutineSettings(PDO $db, array $input): void
{
    if (!tableExists($db, 'routine_settings')) {
        throw new RuntimeException('Routine settings table is missing.');
    }
    $values = [
        'assembly_label' => trim((string) ($input['assembly_label'] ?? 'সমাবেশ')) ?: 'সমাবেশ',
        'assembly_time' => trim((string) ($input['assembly_time'] ?? '')),
        'break_label' => trim((string) ($input['break_label'] ?? 'বিরতি')) ?: 'বিরতি',
        'break_time' => trim((string) ($input['break_time'] ?? '')),
        'session_label' => trim((string) ($input['session_label'] ?? '')),
        'footer_note' => trim((string) ($input['footer_note'] ?? '')),
        'is_published' => !empty($input['is_published']) ? 1 : 0,
    ];
    $id = (int) ($db->query('SELECT id FROM routine_settings ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    if ($id > 0) {
        $values['id'] = $id;
        $db->prepare(
            'UPDATE routine_settings
             SET assembly_label = :assembly_label, assembly_time = :assembly_time,
                 break_label = :break_label, break_time = :break_time,
                 session_label = :session_label, footer_note = :footer_note,
                 is_published = :is_published
             WHERE id = :id'
        )->execute($values);

        return;
    }

    $db->prepare(
        'INSERT INTO routine_settings
         (assembly_label, assembly_time, break_label, break_time, session_label, footer_note, is_published)
         VALUES
         (:assembly_label, :assembly_time, :break_label, :break_time, :session_label, :footer_note, :is_published)'
    )->execute($values);
}

function teacherNameMap(PDO $db): array
{
    $map = [];
    foreach (schoolTeachers($db) as $teacher) {
        $map[(string) $teacher['teacher_mobile']] = (string) $teacher['teacher_name'];
    }

    return $map;
}

function teacherDisplayName(array $map, string $mobile): string
{
    $mobile = trim($mobile);
    if ($mobile === '') {
        return '';
    }
    if (isset($map[$mobile])) {
        return $map[$mobile];
    }
    foreach ($map as $key => $name) {
        if (teacherMobilesMatch((string) $key, $mobile)) {
            return $name;
        }
    }

    return $mobile;
}

function classRoutineGrid(PDO $db, int $classId, int $sectionId): array
{
    if (!tableExists($db, 'class_routine') || $classId < 1) {
        return [];
    }
    $rows = fetchRows(
        $db,
        'SELECT * FROM class_routine
         WHERE class_id = :c AND section_id = :s
         ORDER BY day_of_week ASC, period_no ASC, id ASC',
        ['c' => $classId, 's' => $sectionId]
    );
    $grid = [];
    foreach ($rows as $row) {
        $grid[(int) $row['day_of_week']][(int) $row['period_no']][] = $row;
    }

    return $grid;
}

function groupTeacherRoutineCells(PDO $db, array $cells): array
{
    $groups = [];
    $order = [];
    foreach ($cells as $cell) {
        $subject = trim((string) ($cell['subject_name'] ?? ''));
        $teacherKey = teacherMobileKey((string) ($cell['teacher_mobile'] ?? ''));
        $groupKey = $teacherKey . '|' . mb_strtolower($subject, 'UTF-8');
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'subject_name' => $subject,
                'teacher_mobile' => (string) ($cell['teacher_mobile'] ?? ''),
                'room' => trim((string) ($cell['room'] ?? '')),
                'class_labels' => [],
            ];
            $order[] = $groupKey;
        }
        $classSectionLabel = trim(
            classLabel($db, (int) ($cell['class_id'] ?? 0)) . ' ' . sectionLabel($db, (int) ($cell['section_id'] ?? 0))
        );
        if ($classSectionLabel !== '' && !in_array($classSectionLabel, $groups[$groupKey]['class_labels'], true)) {
            $groups[$groupKey]['class_labels'][] = $classSectionLabel;
        }
        $room = trim((string) ($cell['room'] ?? ''));
        if ($groups[$groupKey]['room'] === '' && $room !== '') {
            $groups[$groupKey]['room'] = $room;
        }
    }

    $result = [];
    foreach ($order as $groupKey) {
        $result[] = $groups[$groupKey];
    }

    return $result;
}

function classRoutineStats(PDO $db, int $classId, int $sectionId, array $days, array $periods): array
{
    $grid = classRoutineGrid($db, $classId, $sectionId);
    $total = count($days) * count($periods);
    $filled = 0;
    $teachers = [];
    foreach ($grid as $daySlots) {
        foreach ($daySlots as $slotRows) {
            $hasSubject = false;
            foreach ($slotRows as $slot) {
                if (trim((string) ($slot['subject_name'] ?? '')) === '') {
                    continue;
                }
                $hasSubject = true;
                $mobile = trim((string) ($slot['teacher_mobile'] ?? ''));
                if ($mobile !== '') {
                    $teachers[$mobile] = true;
                }
            }
            if ($hasSubject) {
                $filled++;
            }
        }
    }

    return [
        'total' => $total,
        'filled' => $filled,
        'empty' => max(0, $total - $filled),
        'teachers' => count($teachers),
        'percent' => $total > 0 ? (int) round(($filled / $total) * 100) : 0,
    ];
}

function routineTeacherConflicts(PDO $db, int $day, int $periodNo, string $teacherMobile, int $classId, int $sectionId): array
{
    $teacherMobile = trim($teacherMobile);
    if ($teacherMobile === '' || !tableExists($db, 'class_routine')) {
        return [];
    }
    $rows = fetchRows(
        $db,
        'SELECT * FROM class_routine
         WHERE day_of_week = :d AND period_no = :p
           AND teacher_mobile IS NOT NULL AND teacher_mobile <> \'\'
           AND subject_name <> \'\'',
        ['d' => $day, 'p' => $periodNo]
    );
    $hits = [];
    foreach ($rows as $row) {
        if ((int) $row['class_id'] === $classId && (int) $row['section_id'] === $sectionId) {
            continue;
        }
        if (teacherMobilesMatch((string) $row['teacher_mobile'], $teacherMobile)) {
            $hits[] = $row;
        }
    }

    return $hits;
}

function saveClassRoutineDay(
    PDO $db,
    int $classId,
    int $sectionId,
    int $day,
    array $periods,
    array $subjects,
    array $teachers,
    array $rooms
): array {
    if ($classId < 1) {
        throw new RuntimeException('Class is required.');
    }
    if (!isset(weekDays()[$day])) {
        throw new RuntimeException('Pick a valid weekday.');
    }

    $entries = [];
    $conflicts = [];
    foreach ($periods as $periodNo => $time) {
        $periodNo = (int) $periodNo;
        $periodSubjects = $subjects[$periodNo] ?? [];
        $periodTeachers = $teachers[$periodNo] ?? [];
        $periodRooms = $rooms[$periodNo] ?? [];
        if (!is_array($periodSubjects)) {
            $periodSubjects = [$periodSubjects];
        }
        if (!is_array($periodTeachers)) {
            $periodTeachers = [$periodTeachers];
        }
        if (!is_array($periodRooms)) {
            $periodRooms = [$periodRooms];
        }
        foreach ($periodSubjects as $entryNo => $subjectValue) {
            if (!is_scalar($subjectValue)) {
                throw new RuntimeException('Invalid routine subject.');
            }
            $subject = trim((string) $subjectValue);
            if ($subject === '') {
                continue;
            }
            $teacherValue = $periodTeachers[$entryNo] ?? '';
            $roomValue = $periodRooms[$entryNo] ?? '';
            if (!is_scalar($teacherValue) || !is_scalar($roomValue)) {
                throw new RuntimeException('Invalid routine teacher or room.');
            }
            $teacherMobile = trim((string) $teacherValue);
            $room = trim((string) $roomValue);
            foreach (routineTeacherConflicts($db, $day, $periodNo, $teacherMobile, $classId, $sectionId) as $row) {
                $conflicts[] = [
                    'period' => $periodNo,
                    'teacher' => $teacherMobile,
                    'class_id' => (int) $row['class_id'],
                    'section_id' => (int) $row['section_id'],
                    'subject' => (string) $row['subject_name'],
                ];
            }
            $entries[] = [
                'period_no' => $periodNo,
                'period_time' => (string) $time,
                'subject_name' => $subject,
                'teacher_mobile' => $teacherMobile !== '' ? $teacherMobile : null,
                'room' => $room !== '' ? $room : null,
            ];
        }
    }

    $db->beginTransaction();
    try {
        $db->prepare(
            'DELETE FROM class_routine
             WHERE class_id = :class_id AND section_id = :section_id AND day_of_week = :day_of_week'
        )->execute(['class_id' => $classId, 'section_id' => $sectionId, 'day_of_week' => $day]);
        $insert = $db->prepare(
            'INSERT INTO class_routine
             (class_id, section_id, day_of_week, period_no, period_time, subject_name, teacher_mobile, room)
             VALUES (:class_id, :section_id, :day_of_week, :period_no, :period_time, :subject_name, :teacher_mobile, :room)'
        );
        foreach ($entries as $entry) {
            $insert->execute([
                'class_id' => $classId,
                'section_id' => $sectionId,
                'day_of_week' => $day,
            ] + $entry);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    return $conflicts;
}

function clearClassRoutineDay(PDO $db, int $classId, int $sectionId, int $day): void
{
    if ($classId < 1) {
        throw new RuntimeException('Class is required.');
    }
    $db->prepare(
        'DELETE FROM class_routine WHERE class_id = :c AND section_id = :s AND day_of_week = :d'
    )->execute(['c' => $classId, 's' => $sectionId, 'd' => $day]);
}

function copyClassRoutineDay(PDO $db, int $classId, int $sectionId, int $fromDay, int $toDay): int
{
    if ($classId < 1 || $fromDay === $toDay || !isset(weekDays()[$fromDay], weekDays()[$toDay])) {
        throw new RuntimeException('Pick two different weekdays.');
    }
    $rows = fetchRows(
        $db,
        'SELECT * FROM class_routine WHERE class_id = :c AND section_id = :s AND day_of_week = :d',
        ['c' => $classId, 's' => $sectionId, 'd' => $fromDay]
    );
    clearClassRoutineDay($db, $classId, $sectionId, $toDay);
    $insert = $db->prepare(
        'INSERT INTO class_routine (class_id, section_id, day_of_week, period_no, period_time, subject_name, teacher_mobile, room)
         VALUES (:class_id, :section_id, :day_of_week, :period_no, :period_time, :subject_name, :teacher_mobile, :room)'
    );
    foreach ($rows as $row) {
        if (trim((string) ($row['subject_name'] ?? '')) === '') {
            continue;
        }
        $insert->execute([
            'class_id' => $classId,
            'section_id' => $sectionId,
            'day_of_week' => $toDay,
            'period_no' => (int) $row['period_no'],
            'period_time' => $row['period_time'],
            'subject_name' => $row['subject_name'],
            'teacher_mobile' => $row['teacher_mobile'],
            'room' => $row['room'],
        ]);
    }

    return count($rows);
}

function copyClassRoutineWeek(PDO $db, int $fromClass, int $fromSection, int $toClass, int $toSection): int
{
    if ($fromClass < 1 || $toClass < 1) {
        throw new RuntimeException('Both source and destination classes are required.');
    }
    if ($fromClass === $toClass && $fromSection === $toSection) {
        throw new RuntimeException('Choose a different class or section to copy into.');
    }
    $rows = fetchRows(
        $db,
        'SELECT * FROM class_routine WHERE class_id = :c AND section_id = :s AND subject_name <> \'\'',
        ['c' => $fromClass, 's' => $fromSection]
    );
    $db->prepare('DELETE FROM class_routine WHERE class_id = :c AND section_id = :s')
        ->execute(['c' => $toClass, 's' => $toSection]);
    $insert = $db->prepare(
        'INSERT INTO class_routine (class_id, section_id, day_of_week, period_no, period_time, subject_name, teacher_mobile, room)
         VALUES (:class_id, :section_id, :day_of_week, :period_no, :period_time, :subject_name, :teacher_mobile, :room)'
    );
    foreach ($rows as $row) {
        $insert->execute([
            'class_id' => $toClass,
            'section_id' => $toSection,
            'day_of_week' => (int) $row['day_of_week'],
            'period_no' => (int) $row['period_no'],
            'period_time' => $row['period_time'],
            'subject_name' => $row['subject_name'],
            'teacher_mobile' => $row['teacher_mobile'],
            'room' => $row['room'],
        ]);
    }

    return count($rows);
}

function teacherDayRoutine(PDO $db, int $day): array
{
    if (!tableExists($db, 'class_routine') || !isset(weekDays()[$day])) {
        return [];
    }
    $rows = fetchRows(
        $db,
        'SELECT * FROM class_routine
         WHERE day_of_week = :d AND subject_name <> \'\'
         ORDER BY period_no ASC, class_id ASC',
        ['d' => $day]
    );
    $nameMap = teacherNameMap($db);
    $grouped = [];
    foreach ($rows as $row) {
        $mobile = trim((string) ($row['teacher_mobile'] ?? ''));
        $key = $mobile !== '' ? $mobile : '__none__';
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'mobile' => $mobile,
                'name' => $mobile !== '' ? teacherDisplayName($nameMap, $mobile) : 'শিক্ষক নির্ধারিত নয়',
                'slots' => [],
                'has_conflict' => false,
            ];
        }
        $period = (int) $row['period_no'];
        $grouped[$key]['slots'][$period][] = $row;
        if (count($grouped[$key]['slots'][$period]) > 1) {
            $grouped[$key]['has_conflict'] = true;
        }
    }
    uasort(
        $grouped,
        static function (array $left, array $right): int {
            if ($left['mobile'] === '' && $right['mobile'] !== '') {
                return 1;
            }
            if ($left['mobile'] !== '' && $right['mobile'] === '') {
                return -1;
            }

            return strcmp($left['name'], $right['name']);
        }
    );

    return $grouped;
}

function routineSubjectTone(string $subject): array
{
    $palette = [
        ['ink' => '#1a4d3e', 'bg' => '#e7f3ed'],
        ['ink' => '#1d4e89', 'bg' => '#e7effa'],
        ['ink' => '#8a3a12', 'bg' => '#f8eee6'],
        ['ink' => '#6b3fa0', 'bg' => '#f3eaf8'],
        ['ink' => '#0f6b5c', 'bg' => '#e4f5f1'],
        ['ink' => '#9a3d5c', 'bg' => '#f8e9ee'],
        ['ink' => '#3d5a80', 'bg' => '#e8eef5'],
        ['ink' => '#7a5c12', 'bg' => '#f6f0dc'],
        ['ink' => '#2d6a4f', 'bg' => '#e6f4ec'],
        ['ink' => '#4a4e69', 'bg' => '#eceef5'],
        ['ink' => '#9c2a2a', 'bg' => '#f8e8e8'],
        ['ink' => '#1b6b93', 'bg' => '#e4f3f8'],
    ];
    $hash = 0;
    $length = mb_strlen($subject);
    for ($i = 0; $i < $length; $i++) {
        $hash = ($hash * 31 + mb_ord(mb_substr($subject, $i, 1))) % 997;
    }

    return $palette[$hash % count($palette)];
}

function subjectTeacherMap(array $subjects): array
{
    $map = [];
    foreach ($subjects as $subject) {
        $label = trim((string) ($subject['subject_name_ban'] ?: $subject['subject_name']));
        $mobile = trim((string) ($subject['teacher_mobile'] ?? ''));
        if ($label !== '' && $mobile !== '') {
            $map[$label] = $mobile;
        }
    }

    return $map;
}

function certificateTypes(): array
{
    return [
        'studentship' => 'অধ্যয়নরত প্রত্যয়নপত্র / Studentship',
        'character' => 'চারিত্রিক সনদ / Character Certificate',
        'transfer' => 'ছাড়পত্র / Transfer Certificate',
        'testimonial' => 'প্রশংসাপত্র / Testimonial',
    ];
}

/** Card print modes (sidebar pages; not certificate dropdown options). */
function certificateCardTypes(): array
{
    return [
        'idcard' => 'আইডি কার্ড / ID Card',
        'seatlevel' => 'পরীক্ষার সিট লেবেল / Seat Label',
    ];
}

function certificateLanguage(string $raw = ''): string
{
    return $raw === 'en' ? 'en' : 'bn';
}

function certificateTypeTitle(string $type, string $lang = 'bn'): string
{
    $titles = [
        'bn' => [
            'studentship' => 'অধ্যয়নরত প্রত্যয়নপত্র',
            'character' => 'চারিত্রিক সনদ',
            'transfer' => 'ছাড়পত্র',
            'testimonial' => 'প্রশংসা পত্র',
            'idcard' => 'আইডি কার্ড',
            'seatlevel' => 'পরীক্ষার সিট লেবেল',
        ],
        'en' => [
            'studentship' => 'অধ্যয়নরত প্রত্যয়ন পত্র / Studentship',
            'character' => 'CHARACTER CERTIFICATE',
            'transfer' => 'Transfer Certificate',
            'testimonial' => 'TESTIMONIAL',
            'idcard' => 'ID Card',
            'seatlevel' => 'SEAT LABEL',
        ],
    ];
    $lang = certificateLanguage($lang);

    return $titles[$lang][$type] ?? $titles['bn']['studentship'];
}

function certificateSheetCopy(string $lang = 'bn'): array
{
    if (certificateLanguage($lang) === 'en') {
        return [
            'date' => 'Date',
            'mobile' => 'Mobile',
            'headTeacher' => 'Head Teacher',
            'note' => 'This certificate is issued according to the records of this institution.',
            'qrTitle' => 'Scan to view student profile',
        ];
    }

    return [
        'date' => 'তারিখ',
        'mobile' => 'মোবাইল',
        'headTeacher' => 'প্রধান শিক্ষক',
        'note' => 'অত্র সনদপত্র অত্র প্রতিষ্ঠানের নথি অনুসারে প্রদান করা হলো।',
        'qrTitle' => 'স্ক্যান করে শিক্ষার্থীর তথ্য দেখুন',
    ];
}

function certificateSchoolHeading(array $settings, string $lang = 'bn'): array
{
    if (certificateLanguage($lang) === 'en') {
        $name = trim((string) ($settings['school_name_en'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($settings['school_name_bn'] ?? ''));
        }
        $address = trim((string) ($settings['address_en'] ?? ''));
        if ($address === '') {
            $address = 'Upazila: Lakshmipur Sadar, District: Lakshmipur.';
        }

        return ['name' => $name, 'address' => $address];
    }

    return [
        'name' => trim((string) ($settings['school_name_bn'] ?? '')),
        'address' => trim((string) ($settings['address'] ?? '')),
    ];
}

function certificateEnglishClassName(array $student): string
{
    $raw = trim((string) ($student['class_name'] ?? ''));
    $ban = trim((string) ($student['class_name_ban'] ?? ''));
    $source = $raw !== '' ? $raw : $ban;
    $key = strtolower((string) preg_replace('/[\s.\-]+/', '', str_ireplace('class', '', $source)));
    $map = [
        'play' => 'Play', 'nursery' => 'Nursery', 'kg' => 'KG',
        'one' => 'One', 'two' => 'Two', 'three' => 'Three', 'four' => 'Four', 'five' => 'Five',
        'six' => 'Six', 'seven' => 'Seven', 'eight' => 'Eight', 'nine' => 'Nine', 'ten' => 'Ten',
        'eleven' => 'Eleven', 'twelve' => 'Twelve',
        '1' => 'One', '2' => 'Two', '3' => 'Three', '4' => 'Four', '5' => 'Five',
        '6' => 'Six', '7' => 'Seven', '8' => 'Eight', '9' => 'Nine', '10' => 'Ten',
        '১' => 'One', '২' => 'Two', '৩' => 'Three', '৪' => 'Four', '৫' => 'Five',
        '৬' => 'Six', '৭' => 'Seven', '৮' => 'Eight', '৯' => 'Nine', '১০' => 'Ten',
        'ষষ্ঠ' => 'Six', 'সপ্তম' => 'Seven', 'অষ্টম' => 'Eight', 'নবম' => 'Nine', 'দশম' => 'Ten',
        '৬ষ্ঠ' => 'Six', '৭ম' => 'Seven', '৮ম' => 'Eight', '৯ম' => 'Nine', '১০ম' => 'Ten',
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }

    return $raw !== '' ? ucwords(strtolower($raw)) : $ban;
}

function certificateJoinNonEmpty(array $parts, string $separator = ', '): string
{
    $clean = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
            $clean[] = $part;
        }
    }

    return implode($separator, $clean);
}

function certificateLabeledValue(string $label, string $value): string
{
    $value = trim($value);

    return $value === '' ? '' : $label . $value;
}

/** Mark a value for bold+italic rendering via certificateBodyHtml(). */
function certificateEmphasis(string $value, string $empty = '—'): string
{
    $value = trim($value);
    if ($value === '') {
        $value = $empty;
    }

    return "\x1E{$value}\x1F";
}

/** Escape certificate body text; convert emphasis markers to styled spans (mPDF-friendly). */
function certificateBodyHtml(string $body): string
{
    return str_replace(
        ["\x1E", "\x1F"],
        [
            '<span class="cert-em" style="font-family:hindsiliguri;font-weight:bold;font-style:italic;">',
            '</span>',
        ],
        h($body)
    );
}

function certificateEnglishSmallNumberWord(int $n): string
{
    $ones = [
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
    ];
    $tens = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
    ];
    if ($n < 20) {
        return $ones[$n];
    }
    $t = intdiv($n, 10);
    $o = $n % 10;

    return $o === 0 ? $tens[$t] : $tens[$t] . '-' . $ones[$o];
}

function certificateEnglishOrdinalDayWord(int $day): string
{
    $map = [
        1 => 'First', 2 => 'Second', 3 => 'Third', 4 => 'Fourth', 5 => 'Fifth',
        6 => 'Sixth', 7 => 'Seventh', 8 => 'Eighth', 9 => 'Ninth', 10 => 'Tenth',
        11 => 'Eleventh', 12 => 'Twelfth', 13 => 'Thirteenth', 14 => 'Fourteenth', 15 => 'Fifteenth',
        16 => 'Sixteenth', 17 => 'Seventeenth', 18 => 'Eighteenth', 19 => 'Nineteenth', 20 => 'Twentieth',
        21 => 'Twenty-first', 22 => 'Twenty-second', 23 => 'Twenty-third', 24 => 'Twenty-fourth',
        25 => 'Twenty-fifth', 26 => 'Twenty-sixth', 27 => 'Twenty-seventh', 28 => 'Twenty-eighth',
        29 => 'Twenty-ninth', 30 => 'Thirtieth', 31 => 'Thirty-first',
    ];

    return $map[$day] ?? (string) $day;
}

function certificateEnglishYearWords(int $year): string
{
    if ($year < 1000 || $year > 9999) {
        return (string) $year;
    }
    $thousands = intdiv($year, 1000);
    $remainder = $year % 1000;
    $parts = [certificateEnglishSmallNumberWord($thousands), 'Thousand'];
    if ($remainder === 0) {
        return implode(' ', $parts);
    }
    if ($remainder < 100) {
        $parts[] = certificateEnglishSmallNumberWord($remainder);

        return implode(' ', $parts);
    }
    $hundreds = intdiv($remainder, 100);
    $rest = $remainder % 100;
    $parts[] = certificateEnglishSmallNumberWord($hundreds);
    $parts[] = 'Hundred';
    if ($rest > 0) {
        $parts[] = certificateEnglishSmallNumberWord($rest);
    }

    return implode(' ', $parts);
}

/** Example: 22-09-2006 (Twenty-second September Two Thousand Six) */
function certificateEnglishDateWithWords(DateTimeInterface $date): string
{
    $numeric = $date->format('d-m-Y');
    $dayWord = certificateEnglishOrdinalDayWord((int) $date->format('j'));
    $month = $date->format('F');
    $yearWord = certificateEnglishYearWords((int) $date->format('Y'));

    return $numeric . ' (' . $dayWord . ' ' . $month . ' ' . $yearWord . ')';
}

function certificateSkipGenericNote(string $type, string $lang = 'bn'): bool
{
    return $type === 'studentship' || $type === 'character' || $type === 'transfer' || $type === 'testimonial';
}

function certificateDisplayDate(string $lang = 'bn', ?string $date = null): string
{
    $raw = trim((string) ($date ?? date('d/m/Y')));
    if ($raw === '') {
        $raw = date('d/m/Y');
    }
    if (certificateLanguage($lang) === 'en') {
        return $raw;
    }

    return bnDigits($raw) . 'খ্রি:';
}

function certificatePreviousSessionYear(string $sessionYear): string
{
    $ascii = asciiDigits(trim($sessionYear));
    if ($ascii === '' || !ctype_digit($ascii)) {
        return '';
    }

    return (string) max(0, ((int) $ascii) - 1);
}

function certificateBanglaClassSequence(): array
{
    return [
        'প্লে', 'নার্সারি', 'কেজি',
        'প্রথম', 'দ্বিতীয়', 'তৃতীয়', 'চতুর্থ', 'পঞ্চম',
        'ষষ্ঠ', 'সপ্তম', 'অষ্টম', 'নবম', 'দশম',
        'একাদশ', 'দ্বাদশ',
    ];
}

function certificateEnglishClassSequence(): array
{
    return [
        'Play', 'Nursery', 'KG',
        'One', 'Two', 'Three', 'Four', 'Five',
        'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve',
    ];
}

function certificatePreviousClassName(array $student, string $currentClassBan): string
{
    $current = trim((string) preg_replace('/\s*শ্রেণী\s*/u', '', $currentClassBan));
    $bnSeq = certificateBanglaClassSequence();
    foreach ($bnSeq as $index => $label) {
        if ($current === $label || ($currentClassBan !== '' && mb_strpos($currentClassBan, $label) !== false)) {
            return $index > 0 ? $bnSeq[$index - 1] : '';
        }
    }

    $enCurrent = certificateEnglishClassName($student);
    $enSeq = certificateEnglishClassSequence();
    foreach ($enSeq as $index => $label) {
        if (strcasecmp($enCurrent, $label) === 0) {
            return $index > 0 ? ($bnSeq[$index - 1] ?? '') : '';
        }
    }

    $lastClass = trim((string) ($student['last_class'] ?? ''));
    if ($lastClass !== '' && $lastClass !== $currentClassBan && $lastClass !== $current) {
        return $lastClass;
    }

    return '';
}

function certificateDocumentBody(array $student, string $type, array $settings, string $lang = 'bn'): string
{
    $lang = certificateLanguage($lang);
    $roll = trim((string) ($student['cls_roll'] ?? ''));
    $villageBn = trim((string) ($student['village'] ?? ''));
    $villageEn = trim((string) ($student['village_en'] ?? ''));
    $postOfficeBn = trim((string) ($student['post_office'] ?? ''));
    $postOfficeEn = trim((string) ($student['post_office_en'] ?? ''));

    if ($lang === 'en') {
        $name = trim((string) ($student['name_eng'] ?? '')) ?: trim((string) ($student['name_ban'] ?? ''));
        $father = trim((string) ($student['fname_eng'] ?? '')) ?: trim((string) ($student['fname_ban'] ?? ''));
        $mother = trim((string) ($student['mname_eng'] ?? '')) ?: trim((string) ($student['mname_ban'] ?? ''));
        $className = certificateEnglishClassName($student);
        $sectionName = trim((string) ($student['section_name'] ?? '')) ?: trim((string) ($student['section_name_ban'] ?? ''));
        $gender = strtolower(trim((string) ($student['gender'] ?? '')));
        $childOf = $gender === 'female' ? 'daughter of' : ($gender === 'male' ? 'son of' : 'son/daughter of');
        $pronoun = $gender === 'female' ? 'her' : ($gender === 'male' ? 'his' : 'his/her');
        $pronounCap = $gender === 'female' ? 'Her' : ($gender === 'male' ? 'His' : 'His/her');
        $heShe = $gender === 'female' ? 'she' : ($gender === 'male' ? 'he' : 'he/she');
        $himHer = $gender === 'female' ? 'her' : ($gender === 'male' ? 'him' : 'him/her');
        $village = $villageEn !== '' ? $villageEn : $villageBn;
        $postOffice = $postOfficeEn !== '' ? $postOfficeEn : $postOfficeBn;
        $upazila = trim((string) ($student['upazilla_en'] ?? $student['upazilla_bn'] ?? ''));
        $district = trim((string) ($student['district_en'] ?? $student['district_bn'] ?? ''));
        $birthRaw = trim((string) ($student['birth_date'] ?? ''));
        $birthDate = ($birthRaw !== '' && !str_starts_with($birthRaw, '0000-00-00')) ? date_create($birthRaw) : false;
        $birth = $birthDate instanceof DateTimeInterface ? $birthDate->format('d-m-Y') : '';
        $parentNames = certificateJoinNonEmpty([$father, $mother], ' and ');
        $parentPhrase = $parentNames !== '' ? ', ' . $childOf . ' ' . $parentNames : '';
        $address = certificateJoinNonEmpty([
            certificateLabeledValue('Village- ', $village),
            certificateLabeledValue('Post Office- ', $postOffice),
            certificateLabeledValue('Upazila- ', $upazila),
            certificateLabeledValue('District- ', $district),
        ]);
        $addressPhrase = $address !== '' ? ', ' . $address : '';
        $classLine = certificateJoinNonEmpty([
            certificateLabeledValue('Class ', $className),
            certificateLabeledValue('Section- ', $sectionName),
        ]);
        $studyPhrase = $classLine !== ''
            ? ' is a regular student of ' . $classLine . ' of this school'
            : ' is a regular student of this school';
        $facts = certificateJoinNonEmpty([
            $birth !== '' ? $pronounCap . ' date of birth is ' . $birth : '',
            $roll !== '' ? 'class roll number is ' . $roll : '',
        ], ' and ');
        $factSentence = $facts !== '' ? ' ' . $facts . '.' : '';
        $characterBody = "This is to certify that {$name}{$parentPhrase}{$addressPhrase}{$studyPhrase}.{$factSentence}\n\nTo the best of my knowledge, {$heShe} has not been involved in any activity contrary to school discipline or the state. {$pronounCap} moral character is good.\n\nI wish {$himHer} all-round success and prosperity in {$pronoun} life.";

        $transferClassLine = certificateJoinNonEmpty([
            certificateLabeledValue('Class ', $className),
            certificateLabeledValue('Section: ', $sectionName),
        ]);
        $transferStudy = $transferClassLine !== ''
            ? ' was a student of ' . $transferClassLine . ' of this institution'
            : ' was a student of this institution';
        $transferFacts = certificateJoinNonEmpty([
            $birth !== '' ? $pronounCap . ' date of birth is ' . $birth : '',
            $roll !== '' ? 'class roll no. is ' . $roll : '',
        ], ' and ');
        $transferFactSentence = $transferFacts !== '' ? ' ' . $transferFacts . '.' : '';
        $transferBody = "This is to certify that {$name}{$parentPhrase}{$addressPhrase}{$transferStudy}.{$transferFactSentence}\n\n"
            . "To the best of my knowledge, {$heShe} has not been involved in any activity contrary to school discipline or the state. {$pronounCap} moral character is good. There is no objection to {$pronoun} leaving the school.\n\n"
            . "I wish {$himHer} all-round success and prosperity in {$pronoun} life.";

        $sscBatch = trim((string) ($student['ssc_batch_year'] ?? ''));
        $sscSession = trim((string) ($student['ssc_session'] ?? ''));
        $sscReg = trim((string) ($student['ssc_reg_no'] ?? ''));
        $sscRoll = trim((string) ($student['ssc_roll_no'] ?? ''));
        $sscResult = trim((string) ($student['ssc_result'] ?? ''));
        $sscType = sscStudentTypeLabel((string) ($student['ssc_student_type'] ?? ''), 'en');
        $sscGroupEn = trim((string) ($student['ssc_group_en'] ?? '')) ?: sscGroupEnglish((string) ($student['ssc_group'] ?? ''));
        $schoolNameEn = trim((string) (certificateSchoolHeading($settings, 'en')['name'] ?? ''));
        $birthLong = $birthDate instanceof DateTimeInterface
            ? certificateEnglishDateWithWords($birthDate)
            : '';
        $heCap = $gender === 'female' ? 'She' : ($gender === 'male' ? 'He' : 'He/She');
        $his = $gender === 'female' ? 'her' : ($gender === 'male' ? 'his' : 'his/her');
        $testimonialBody = 'This is to certify that ' . certificateEmphasis($name)
            . ", Father's Name: " . certificateEmphasis($father)
            . ", Mother's Name: " . certificateEmphasis($mother)
            . ' was a student of ' . ($schoolNameEn !== '' ? $schoolNameEn : 'this institution') . '. '
            . $heCap . ' passed the S.S.C examination in ' . certificateEmphasis($sscBatch)
            . ' with bearing Roll ' . certificateEmphasis($sscRoll)
            . ', Reg No. ' . certificateEmphasis($sscReg)
            . ', Session Year ' . certificateEmphasis($sscSession)
            . ', Student Type ' . certificateEmphasis($sscType)
            . ($sscGroupEn !== '' ? ', Group ' . certificateEmphasis($sscGroupEn) : '')
            . ' and secured a G.P.A. of ' . certificateEmphasis($sscResult)
            . ' in the Cumilla Board.'
            . "\n\n" . $pronounCap . ' Date of Birth: ' . certificateEmphasis($birthLong)
            . "\n\nDuring {$his} stay in this institution, {$his} conduct was satisfactory. "
            . "To the best of my knowledge, {$heShe} has not been involved in any activity contrary to school discipline or state."
            . "\n\nI wish {$himHer} a bright future and a successful life ahead.";

        $bodies = [
            'studentship' => $characterBody,
            'character' => $characterBody,
            'transfer' => $transferBody,
            'testimonial' => $testimonialBody,
        ];

        return $bodies[$type] ?? $bodies['studentship'];
    }

    $name = trim((string) ($student['name_ban'] ?? '')) ?: trim((string) ($student['name_eng'] ?? ''));
    $father = trim((string) ($student['fname_ban'] ?? ''));
    $mother = trim((string) ($student['mname_ban'] ?? ''));
    $className = trim((string) ($student['class_name_ban'] ?? '')) ?: trim((string) ($student['class_name'] ?? ''));
    $sectionName = trim((string) ($student['section_name_ban'] ?? '')) ?: trim((string) ($student['section_name'] ?? ''));
    $village = $villageBn;
    $postOffice = $postOfficeBn;
    $upazila = trim((string) ($student['upazilla_bn'] ?? $student['upazilla_en'] ?? ''));
    $district = trim((string) ($student['district_bn'] ?? $student['district_en'] ?? ''));
    $gender = strtolower(trim((string) ($student['gender'] ?? '')));
    $studentWord = $gender === 'female' ? 'ছাত্রী' : ($gender === 'male' ? 'ছাত্র' : 'ছাত্র/ছাত্রী');
    $birthRaw = trim((string) ($student['birth_date'] ?? ''));
    $birthDate = ($birthRaw !== '' && !str_starts_with($birthRaw, '0000-00-00')) ? date_create($birthRaw) : false;
    $birth = $birthDate instanceof DateTimeInterface ? bnDigits($birthDate->format('d/m/Y')) : '';
    $rollBn = $roll !== '' ? bnDigits($roll) : '';

    $introParts = certificateJoinNonEmpty([
        $name,
        certificateLabeledValue('পিতা- ', $father),
        certificateLabeledValue('মাতা- ', $mother),
        certificateLabeledValue('গ্রামঃ ', $village),
        certificateLabeledValue('ডাকঘরঃ ', $postOffice),
        certificateLabeledValue('উপজেলা- ', $upazila),
        certificateLabeledValue('জেলা: ', $district),
    ]);

    if ($className !== '' && $sectionName !== '') {
        $studyCore = $className . ' শ্রেণীর ' . $sectionName . ' শাখার';
    } elseif ($className !== '') {
        $studyCore = $className . ' শ্রেণীর';
    } elseif ($sectionName !== '') {
        $studyCore = $sectionName . ' শাখার';
    } else {
        $studyCore = '';
    }
    $studyPhrase = $studyCore !== ''
        ? ' অত্র বিদ্যালয়ে ' . $studyCore . ' নিয়মিত ' . $studentWord
        : ' অত্র বিদ্যালয়ে নিয়মিত ' . $studentWord;

    $factBits = certificateJoinNonEmpty([
        $birth !== '' ? 'জন্ম তারিখ- ' . $birth . 'খ্রি:' : '',
        $rollBn !== '' ? 'রোল নং- ' . $rollBn : '',
    ]);
    $factPhrase = $factBits !== '' ? '। তার ' . $factBits . '।' : '।';

    $studentshipBody = 'এই মর্মে প্রত্যয়ন করা যাইতেছে যে, ' . $introParts . $studyPhrase . $factPhrase
        . "\n\nঅদ্যাবদি সে বিদ্যালয়ের নিয়মানুবর্তিতার বিরোধী কিংবা রাষ্ট্র বিরোধী কোন কাজে লিপ্ত হয় নাই। তাহার নৈতিক চরিত্র ভাল।\n\n"
        . 'আমি তার জীবনের সর্বাঙ্গীন উন্নতি কামনা করি।';

    $transferIntro = certificateJoinNonEmpty([
        $name,
        certificateLabeledValue('পিতা: ', $father),
        certificateLabeledValue('মাতা: ', $mother),
        certificateLabeledValue('গ্রাম: ', $village),
        certificateLabeledValue('ডাকঘর: ', $postOffice),
        certificateLabeledValue('উপজেলা: ', $upazila),
        certificateLabeledValue('জেলা: ', $district),
    ]);
    $wasClassPhrase = $className !== ''
        ? ' অত্র বিদ্যালয়ের ' . $className . ' শ্রেণীর ' . $studentWord . ' ছিল।'
        : ' অত্র বিদ্যালয়ের ' . $studentWord . ' ছিল।';
    $transferFacts = certificateJoinNonEmpty([
        $birth !== '' ? 'জন্ম তারিখ: ' . $birth . 'খ্রি:' : '',
        certificateLabeledValue('শাখা: ', $sectionName),
        $rollBn !== '' ? 'রোল নং: ' . $rollBn : '',
    ]);
    $transferFactPhrase = $transferFacts !== '' ? ' তাহার ' . $transferFacts . ' ।' : '';
    $transferBody = 'প্রত্যয়ন করা যাচ্ছে যে, ' . $transferIntro . ',' . $wasClassPhrase . $transferFactPhrase
        . ' তাহার যাবতীয় পাওনা পরিশোধিত আছে। অত্র বিদ্যালয়ে থাকাকালীন তার চরিত্র ভাল ছিল।'
        . ' আমার জানামতে সে বিদ্যালয়ের নিয়মানুবর্তিতার বিরোধী কিংবা রাষ্ট্রীয় আইন শৃঙ্খলা পরিপন্থী কোন কাজে জড়িত ছিল না। তাহার নৈতিক চরিত্র ভাল।'
        . ' তার বিদ্যালয় পরিত্যাগের ব্যাপারে কোনো আপত্তি নেই।'
        . "\n\nআমি তার উজ্জ্বল ভবিষ্যৎ কামনা করি।";

    $sscBatch = trim((string) ($student['ssc_batch_year'] ?? ''));
    $sscSession = trim((string) ($student['ssc_session'] ?? ''));
    $sscReg = trim((string) ($student['ssc_reg_no'] ?? ''));
    $sscRoll = trim((string) ($student['ssc_roll_no'] ?? ''));
    $sscResult = trim((string) ($student['ssc_result'] ?? ''));
    $sscType = sscStudentTypeLabel((string) ($student['ssc_student_type'] ?? ''), 'bn');
    $sscGroupBn = trim((string) ($student['ssc_group'] ?? ''));
    $fatherBn = trim((string) ($student['fname_ban'] ?? '')) ?: trim((string) ($student['fname_eng'] ?? ''));
    $motherBn = trim((string) ($student['mname_ban'] ?? '')) ?: trim((string) ($student['mname_eng'] ?? ''));
    $birthBn = $birthDate instanceof DateTimeInterface
        ? bnDigits($birthDate->format('d-m-Y'))
        : '';
    $testimonialBody = 'প্রত্যয়ন করা যাচ্ছে যে, ' . certificateEmphasis($name)
        . ', পিতার নাম: ' . certificateEmphasis($fatherBn)
        . ', মাতার নাম: ' . certificateEmphasis($motherBn)
        . ' অত্র বিদ্যালয় হইতে ' . certificateEmphasis(bnDigits($sscBatch))
        . ' সনে মাধ্যমিক ও উচ্চ মাধ্যমিক শিক্ষা বোর্ড, কুমিল্লা এর অধীনে এস.এস.সি পরীক্ষায় '
        . ($sscGroupBn !== '' ? certificateEmphasis($sscGroupBn) . ' বিভাগ থেকে ' : '')
        . certificateEmphasis($sscType)
        . ' পরীক্ষার্থী হিসাবে অংশগ্রহণ করে জি.পি.এ ' . certificateEmphasis(bnDigits($sscResult))
        . ' পেয়ে উত্তীর্ণ হয়েছে। তাহার এস.এস.সি রোল নং- ' . certificateEmphasis(bnDigits($sscRoll))
        . ', রেজিষ্ট্রেশন নং- ' . certificateEmphasis(bnDigits($sscReg))
        . ', শিক্ষাবর্ষ: ' . certificateEmphasis(bnDigits($sscSession)) . '।'
        . "\n\nজন্ম তারিখ: " . certificateEmphasis($birthBn) . '।'
        . "\n\nপাঠ্য বিষয়ে ও সহ পাঠ্যক্রমিক বিষয়ে সে সমভাবে অনুরাগী ছিল। তাহার স্বভাব ও নৈতিক চরিত্র সন্তোষজনক। "
        . 'অত্র বিদ্যালয়ে অধ্যয়নকালে সে বিদ্যালয় বা রাষ্ট্রীয় স্বার্থের পরিপন্থী কোন কাজে লিপ্ত ছিল না। '
        . 'আমার জানামতে তাহার স্বভাব ও নৈতিক চরিত্র সন্তোষজনক।'
        . "\n\nআমি তার উজ্জ্বল ভবিষ্যৎ কামনা করি।";

    $bodies = [
        'studentship' => $studentshipBody,
        'character' => $studentshipBody,
        'transfer' => $transferBody,
        'testimonial' => $testimonialBody,
    ];

    return $bodies[$type] ?? $bodies['studentship'];
}

function renderCropperField(string $id, string $hiddenName, string $label, string $hint, bool $required = false): void
{
    ?>
    <div class="mb-3">
      <label class="form-label" for="<?= h($id) ?>"><?= h($label) ?><?php if ($required): ?> <span class="text-danger">*</span><?php endif; ?></label>
      <input class="form-control" type="file" id="<?= h($id) ?>" accept="image/jpeg,image/png,image/webp" <?= $required ? 'required' : '' ?>>
      <input type="hidden" name="<?= h($hiddenName) ?>" id="<?= h($id) ?>Data">
      <small class="text-secondary d-block"><?= h($hint) ?></small>
      <div id="<?= h($id) ?>Preview" class="mt-2 cropper-preview-wrap" style="max-width:360px;display:none">
        <img id="<?= h($id) ?>Image" alt="Crop preview" style="max-width:100%">
      </div>
    </div>
    <?php
}

function studentsListJoinSql(string $studentAlias = 's', string $classAlias = 'c', string $sectionAlias = 'sec'): string
{
    return " LEFT JOIN cls {$classAlias} ON {$studentAlias}.cls = {$classAlias}.id"
        . " LEFT JOIN sections {$sectionAlias} ON CAST({$studentAlias}.sections AS CHAR) = CAST({$sectionAlias}.id AS CHAR)";
}

function studentsListOrderSql(string $studentAlias = 's', string $classAlias = 'c', string $sectionAlias = 'sec'): string
{
    $student = $studentAlias === '' ? '' : $studentAlias . '.';
    $class = $classAlias === '' ? '' : $classAlias . '.';
    $section = $sectionAlias === '' ? '' : $sectionAlias . '.';

    return "CAST({$student}session_year AS UNSIGNED) DESC, {$class}sort_order ASC, {$section}sort_order ASC, CAST({$student}cls_roll AS UNSIGNED) ASC, {$student}id ASC";
}

function studentsQueryFilters(array $get): array
{
    $where = [];
    $params = [];
    $search = trim((string) ($get['q'] ?? ''));
    $sessionYear = trim((string) ($get['session_year'] ?? ''));
    $classId = trim((string) ($get['cls'] ?? ''));
    $sectionId = trim((string) ($get['sections'] ?? ''));
    $status = trim((string) ($get['status'] ?? ''));
    $rollFilter = trim((string) ($get['cls_roll'] ?? ''));

    if ($search !== '') {
        $where[] = '(s.name_ban LIKE :q_name_ban OR s.name_eng LIKE :q_name_eng OR s.mobile LIKE :q_mobile OR s.cls_roll LIKE :q_roll OR s.admission_token LIKE :q_token)';
        $like = '%' . $search . '%';
        $params['q_name_ban'] = $like;
        $params['q_name_eng'] = $like;
        $params['q_mobile'] = $like;
        $params['q_roll'] = $like;
        $params['q_token'] = $like;
    }
    if ($sessionYear !== '') {
        $where[] = 's.session_year = :session_year';
        $params['session_year'] = $sessionYear;
    }
    if ($classId !== '') {
        $where[] = 's.cls = :cls';
        $params['cls'] = (int) $classId;
    }
    if ($sectionId !== '') {
        $where[] = 's.sections = :sections';
        $params['sections'] = $sectionId;
    }
    if ($status !== '') {
        $where[] = 's.status = :status';
        $params['status'] = (int) $status;
    }
    if ($rollFilter !== '') {
        $rollValues = studentIdCardRollFilterValues($rollFilter);
        if ($rollValues !== []) {
            $placeholders = [];
            foreach ($rollValues as $index => $value) {
                $key = 'roll_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $value;
            }
            $where[] = 's.cls_roll IN (' . implode(', ', $placeholders) . ')';
        }
    }

    return [$where, $params];
}

function teacherMobileKey(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile) ?? '';

    return ltrim($digits, '0');
}

function teacherMobilesMatch(string $left, string $right): bool
{
    $a = teacherMobileKey($left);
    $b = teacherMobileKey($right);

    return $a !== '' && $a === $b;
}

function subjectAssignedToTeacher(array $subject, string $teacherMobile): bool
{
    return teacherMobilesMatch((string) ($subject['teacher_mobile'] ?? ''), $teacherMobile);
}

function teacherAssignedSubjects(PDO $db, string $teacherMobile, bool $activeOnly = true): array
{
    if (!tableExists($db, 'class_subjects') || teacherMobileKey($teacherMobile) === '') {
        return [];
    }

    $rows = fetchRows(
        $db,
        'SELECT * FROM class_subjects' . ($activeOnly ? ' WHERE is_active = 1' : '')
    );
    $assigned = [];
    foreach ($rows as $row) {
        if (subjectAssignedToTeacher($row, $teacherMobile)) {
            $assigned[] = $row;
        }
    }

    return $assigned;
}

function filterSubjectsForTeacher(array $subjects, string $teacherMobile): array
{
    return array_values(array_filter(
        $subjects,
        static fn (array $subject): bool => subjectAssignedToTeacher($subject, $teacherMobile)
    ));
}

function classSubjectById(PDO $db, int $subjectId): ?array
{
    if (!tableExists($db, 'class_subjects') || $subjectId < 1) {
        return null;
    }
    $rows = fetchRows($db, 'SELECT * FROM class_subjects WHERE id = :id LIMIT 1', ['id' => $subjectId]);

    return $rows[0] ?? null;
}

function marksByStudentForSubject(
    PDO $db,
    int $classId,
    int $sectionId,
    string $sessionYear,
    int $examId,
    int $subjectId,
    bool $requireFinal = false
): array {
    if (!tableExists($db, 'student_marks')) {
        return [];
    }
    // Marks a teacher only "temporarily saved" (draft) must stay invisible to
    // everyone except the entering teacher until Final Submit. A missing row
    // means the subject's marks were entered before this workflow existed
    // (or by direct admin entry), so it stays visible for backward-compat.
    if ($requireFinal) {
        $submission = markSubmissionRow($db, $classId, $sectionId, $sessionYear, $examId, $subjectId);
        if ($submission !== null && (string) $submission['status'] !== 'submitted') {
            return [];
        }
    }
    $rows = fetchRows(
        $db,
        'SELECT * FROM student_marks
         WHERE class_id = :class_id AND section_id = :section_id
           AND session_year = :session_year AND exam_id = :exam_id
           AND class_subject_id = :subject_id',
        [
            'class_id' => $classId,
            'section_id' => $sectionId,
            'session_year' => $sessionYear,
            'exam_id' => $examId,
            'subject_id' => $subjectId,
        ]
    );
    $map = [];
    foreach ($rows as $row) {
        $studentId = (int) ($row['student_id'] ?? 0);
        if ($studentId > 0) {
            $map['id:' . $studentId] = $row;
        }
        $map['roll:' . (string) $row['cls_roll']] = $row;
    }

    return $map;
}

function upsertStudentSubjectMark(
    PDO $db,
    int $studentId,
    string $roll,
    int $classId,
    int $sectionId,
    string $sessionYear,
    int $examId,
    int $subjectId,
    ?float $mcq,
    ?float $cq,
    ?float $practical,
    ?string $enteredBy
): void {
    $sql = 'INSERT INTO student_marks
                (student_id, cls_roll, class_id, section_id, session_year, exam_id, class_subject_id, mcq, cq, practical'
        . (columnExists($db, 'student_marks', 'entered_by') ? ', entered_by' : '')
        . ')
            VALUES
                (:student_id, :cls_roll, :class_id, :section_id, :session_year, :exam_id, :class_subject_id, :mcq, :cq, :practical'
        . (columnExists($db, 'student_marks', 'entered_by') ? ', :entered_by' : '')
        . ')
            ON DUPLICATE KEY UPDATE
                mcq = VALUES(mcq), cq = VALUES(cq), practical = VALUES(practical), student_id = VALUES(student_id)'
        . (columnExists($db, 'student_marks', 'entered_by') ? ', entered_by = VALUES(entered_by)' : '');
    $params = [
        'student_id' => $studentId,
        'cls_roll' => $roll,
        'class_id' => $classId,
        'section_id' => $sectionId,
        'session_year' => $sessionYear,
        'exam_id' => $examId,
        'class_subject_id' => $subjectId,
        'mcq' => $mcq,
        'cq' => $cq,
        'practical' => $practical,
    ];
    if (columnExists($db, 'student_marks', 'entered_by')) {
        $params['entered_by'] = $enteredBy;
    }
    $db->prepare($sql)->execute($params);
    // A "temporary save" keeps the mark in draft state (hidden from Admin
    // Panel / public marksheets) until the teacher clicks Final Submit. This
    // is a no-op once the subject is already draft or already submitted.
    ensureMarkSubmissionDraft($db, $classId, $sectionId, $sessionYear, $examId, $subjectId);
}

function classTeacherAssignments(PDO $db, string $teacherMobile, string $sessionYear = ''): array
{
    if (!tableExists($db, 'class_teachers') || teacherMobileKey($teacherMobile) === '') {
        return [];
    }

    $rows = fetchRows($db, 'SELECT * FROM class_teachers');
    $sessionYear = trim($sessionYear);
    $matched = [];
    foreach ($rows as $row) {
        if (!teacherMobilesMatch((string) ($row['teacher_mobile'] ?? ''), $teacherMobile)) {
            continue;
        }
        $rowSession = trim((string) ($row['session_year'] ?? ''));
        if ($sessionYear !== '' && $rowSession !== '' && $rowSession !== $sessionYear) {
            continue;
        }
        $matched[] = $row;
    }

    return $matched;
}

function classTeacherMap(PDO $db, string $sessionYear): array
{
    if (!tableExists($db, 'class_teachers')) {
        return [];
    }
    $sessionYear = trim($sessionYear);
    $rows = fetchRows(
        $db,
        'SELECT * FROM class_teachers WHERE session_year = :session_year OR session_year = \'\'',
        ['session_year' => $sessionYear]
    );
    $map = [];
    foreach ($rows as $row) {
        $key = (int) $row['class_id'] . ':' . (int) $row['section_id'];
        if ($sessionYear !== '' && trim((string) ($row['session_year'] ?? '')) === $sessionYear) {
            $map[$key] = $row;
            continue;
        }
        if (!isset($map[$key])) {
            $map[$key] = $row;
        }
    }

    return $map;
}

function classesForAttendance(PDO $db, ?array $teacher, string $sessionYear): array
{
    $all = schoolClasses($db);
    if (canViewAllAttendance($teacher)) {
        return $all;
    }
    $allowed = [];
    foreach (classTeacherAssignments($db, (string) ($teacher['teacher_mobile'] ?? ''), $sessionYear) as $row) {
        $allowed[(int) $row['class_id']] = true;
    }

    return array_values(array_filter(
        $all,
        static fn (array $class): bool => isset($allowed[(int) $class['id']])
    ));
}

function sectionsForAttendance(PDO $db, int $classId, ?array $teacher, string $sessionYear): array
{
    $all = $classId > 0 ? schoolSections($db, $classId) : [];
    if (canViewAllAttendance($teacher) || $classId < 1) {
        return $all;
    }
    $sectionIds = [];
    $entireClass = false;
    foreach (classTeacherAssignments($db, (string) ($teacher['teacher_mobile'] ?? ''), $sessionYear) as $row) {
        if ((int) $row['class_id'] !== $classId) {
            continue;
        }
        if ((int) $row['section_id'] === 0) {
            $entireClass = true;
            break;
        }
        $sectionIds[(int) $row['section_id']] = true;
    }
    if ($entireClass) {
        return $all;
    }

    return array_values(array_filter(
        $all,
        static fn (array $section): bool => isset($sectionIds[(int) $section['id']])
    ));
}

function saveClassTeacherAssignment(PDO $db, int $classId, int $sectionId, string $sessionYear, string $teacherMobile): void
{
    if ($classId < 1) {
        throw new RuntimeException('Pick a class first.');
    }
    $teacherMobile = trim($teacherMobile);
    $sessionYear = trim($sessionYear);
    if ($teacherMobile === '') {
        $db->prepare(
            'DELETE FROM class_teachers
             WHERE class_id = :class_id AND section_id = :section_id AND session_year = :session_year'
        )->execute([
            'class_id' => $classId,
            'section_id' => $sectionId,
            'session_year' => $sessionYear,
        ]);

        return;
    }

    $db->prepare(
        'INSERT INTO class_teachers (class_id, section_id, session_year, teacher_mobile)
         VALUES (:class_id, :section_id, :session_year, :teacher_mobile)
         ON DUPLICATE KEY UPDATE teacher_mobile = VALUES(teacher_mobile)'
    )->execute([
        'class_id' => $classId,
        'section_id' => $sectionId,
        'session_year' => $sessionYear,
        'teacher_mobile' => $teacherMobile,
    ]);
}

function deleteStudentSubjectMark(
    PDO $db,
    string $roll,
    int $classId,
    int $sectionId,
    string $sessionYear,
    int $examId,
    int $subjectId
): void {
    $db->prepare(
        'DELETE FROM student_marks
         WHERE cls_roll = :roll AND class_id = :class_id AND section_id = :section_id
           AND session_year = :session_year AND exam_id = :exam_id AND class_subject_id = :sid'
    )->execute([
        'roll' => $roll,
        'class_id' => $classId,
        'section_id' => $sectionId,
        'session_year' => $sessionYear,
        'exam_id' => $examId,
        'sid' => $subjectId,
    ]);
}

function markSubmissionRow(
    PDO $db,
    int $classId,
    int $sectionId,
    string $sessionYear,
    int $examId,
    int $subjectId
): ?array {
    if (!tableExists($db, 'mark_submissions')) {
        return null;
    }
    $rows = fetchRows(
        $db,
        'SELECT * FROM mark_submissions
         WHERE class_id = :class_id AND section_id = :section_id
           AND session_year = :session_year AND exam_id = :exam_id
           AND class_subject_id = :subject_id
         LIMIT 1',
        [
            'class_id' => $classId,
            'section_id' => $sectionId,
            'session_year' => $sessionYear,
            'exam_id' => $examId,
            'subject_id' => $subjectId,
        ]
    );

    return $rows[0] ?? null;
}

function isMarkSubmissionFinal(
    PDO $db,
    int $classId,
    int $sectionId,
    string $sessionYear,
    int $examId,
    int $subjectId
): bool {
    $row = markSubmissionRow($db, $classId, $sectionId, $sessionYear, $examId, $subjectId);

    return $row !== null && (string) $row['status'] === 'submitted';
}

/** Subject ids whose marks are still draft (or never finalized) for the given sheet scope. */
function nonFinalSubjectIds(PDO $db, int $classId, int $sectionId, string $sessionYear, int $examId): array
{
    static $cache = [];
    $key = $classId . '|' . $sectionId . '|' . $sessionYear . '|' . $examId;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!tableExists($db, 'mark_submissions')) {
        return $cache[$key] = [];
    }
    $rows = fetchRows(
        $db,
        "SELECT class_subject_id FROM mark_submissions
         WHERE class_id = :class_id AND section_id = :section_id
           AND session_year = :session_year AND exam_id = :exam_id
           AND status <> 'submitted'",
        [
            'class_id' => $classId,
            'section_id' => $sectionId,
            'session_year' => $sessionYear,
            'exam_id' => $examId,
        ]
    );
    $ids = [];
    foreach ($rows as $row) {
        $ids[(int) $row['class_subject_id']] = true;
    }

    return $cache[$key] = $ids;
}

/** Ensures a draft row exists so unfinalized marks stay hidden from Admin Panel / public pages. */
function ensureMarkSubmissionDraft(
    PDO $db,
    int $classId,
    int $sectionId,
    string $sessionYear,
    int $examId,
    int $subjectId
): void {
    $db->prepare(
        'INSERT IGNORE INTO mark_submissions
            (class_id, section_id, session_year, exam_id, class_subject_id, status)
         VALUES (:class_id, :section_id, :session_year, :exam_id, :subject_id, \'draft\')'
    )->execute([
        'class_id' => $classId,
        'section_id' => $sectionId,
        'session_year' => $sessionYear,
        'exam_id' => $examId,
        'subject_id' => $subjectId,
    ]);
}

function finalizeMarkSubmission(
    PDO $db,
    int $classId,
    int $sectionId,
    string $sessionYear,
    int $examId,
    int $subjectId,
    string $submittedBy
): void {
    $db->prepare(
        'INSERT INTO mark_submissions
            (class_id, section_id, session_year, exam_id, class_subject_id, status, submitted_by, submitted_at)
         VALUES (:class_id, :section_id, :session_year, :exam_id, :subject_id, \'submitted\', :submitted_by, NOW())
         ON DUPLICATE KEY UPDATE
            status = \'submitted\', submitted_by = VALUES(submitted_by), submitted_at = NOW(),
            reopened_by = NULL, reopened_at = NULL'
    )->execute([
        'class_id' => $classId,
        'section_id' => $sectionId,
        'session_year' => $sessionYear,
        'exam_id' => $examId,
        'subject_id' => $subjectId,
        'submitted_by' => $submittedBy,
    ]);
}

/** Head/admin unlock: reopens a finally-submitted subject so its teacher can edit marks again. */
function reopenMarkSubmission(
    PDO $db,
    int $classId,
    int $sectionId,
    string $sessionYear,
    int $examId,
    int $subjectId,
    string $reopenedBy
): void {
    $db->prepare(
        'UPDATE mark_submissions
         SET status = \'draft\', reopened_by = :reopened_by, reopened_at = NOW()
         WHERE class_id = :class_id AND section_id = :section_id
           AND session_year = :session_year AND exam_id = :exam_id
           AND class_subject_id = :subject_id'
    )->execute([
        'reopened_by' => $reopenedBy,
        'class_id' => $classId,
        'section_id' => $sectionId,
        'session_year' => $sessionYear,
        'exam_id' => $examId,
        'subject_id' => $subjectId,
    ]);
}

function deleteStudentRecord(PDO $db, int $id): void
{
    if ($id <= 0) {
        throw new RuntimeException('Student not found.');
    }
    $rows = fetchRows($db, 'SELECT * FROM students_info WHERE id = :id LIMIT 1', ['id' => $id]);
    if ($rows === []) {
        throw new RuntimeException('Student not found.');
    }
    $student = $rows[0];
    deletePublicMediaFile('student_pic', (string) ($student['student_pic'] ?? ''));
    deletePrivateMediaFile('birth_reg_pic', (string) ($student['birth_reg_pic'] ?? ''));

    $roll = (string) ($student['cls_roll'] ?? '');
    $classId = (int) ($student['cls'] ?? 0);
    $sectionId = (int) ($student['sections'] ?? 0);
    $sessionYear = (string) ($student['session_year'] ?? '');

    if (tableExists($db, 'student_attendance')) {
        $db->prepare('DELETE FROM student_attendance WHERE student_id = :id')->execute(['id' => $id]);
    }
    if (tableExists($db, 'student_marks')) {
        $db->prepare('DELETE FROM student_marks WHERE student_id = :id')->execute(['id' => $id]);
        if ($roll !== '' && $classId > 0) {
            $db->prepare(
                'DELETE FROM student_marks
                 WHERE cls_roll = :roll AND class_id = :class_id AND section_id = :section_id AND session_year = :session_year'
            )->execute([
                'roll' => $roll,
                'class_id' => $classId,
                'section_id' => $sectionId,
                'session_year' => $sessionYear,
            ]);
        }
    }
    if (tableExists($db, 'student_exam_remarks') && $roll !== '' && $classId > 0) {
        $db->prepare(
            'DELETE FROM student_exam_remarks
             WHERE cls_roll = :roll AND class_id = :class_id AND section_id = :section_id AND session_year = :session_year'
        )->execute([
            'roll' => $roll,
            'class_id' => $classId,
            'section_id' => $sectionId,
            'session_year' => $sessionYear,
        ]);
    }
    if (tableExists($db, 'marks') && $roll !== '' && $classId > 0) {
        $sql = 'DELETE FROM marks WHERE cls_roll = :roll AND cls_id = :class_id AND sections_id = :section_id';
        $params = ['roll' => $roll, 'class_id' => $classId, 'section_id' => $sectionId];
        if (columnExists($db, 'marks', 'session_year')) {
            $sql .= ' AND session_year = :session_year';
            $params['session_year'] = $sessionYear;
        }
        $db->prepare($sql)->execute($params);
    }

    $db->prepare('DELETE FROM students_info WHERE id = :id')->execute(['id' => $id]);
}

function deleteTeacherRecord(PDO $db, int $id): void
{
    if ($id <= 0) {
        throw new RuntimeException('Teacher not found.');
    }
    $rows = fetchRows($db, 'SELECT * FROM teacher_info WHERE id = :id LIMIT 1', ['id' => $id]);
    if ($rows === []) {
        throw new RuntimeException('Teacher not found.');
    }
    $teacher = $rows[0];
    deletePublicMediaFile('teacher_pic', (string) ($teacher['teacher_pic'] ?? ''));
    deletePrivateMediaFile('teacher_nid_pic', (string) ($teacher['teacher_nid_pic'] ?? ''));

    $mobile = (string) ($teacher['teacher_mobile'] ?? '');
    if ($mobile !== '' && tableExists($db, 'class_teachers')) {
        $db->prepare('DELETE FROM class_teachers WHERE teacher_mobile = :m')->execute(['m' => $mobile]);
    }
    if ($mobile !== '' && tableExists($db, 'class_routine') && columnExists($db, 'class_routine', 'teacher_mobile')) {
        $db->prepare('UPDATE class_routine SET teacher_mobile = NULL WHERE teacher_mobile = :m')->execute(['m' => $mobile]);
    }
    if (tableExists($db, 'comitee') && columnExists($db, 'comitee', 'teacher_id')) {
        foreach (fetchRows($db, 'SELECT id, comitee_picture FROM comitee WHERE teacher_id = :id', ['id' => $id]) as $member) {
            deletePublicMediaFile('comitee_picture', (string) ($member['comitee_picture'] ?? ''));
        }
        $db->prepare('DELETE FROM comitee WHERE teacher_id = :id')->execute(['id' => $id]);
    }

    $db->prepare('DELETE FROM teacher_info WHERE id = :id')->execute(['id' => $id]);
}
