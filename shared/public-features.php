<?php
declare(strict_types=1);

function schoolBanglaMonths(): array
{
    return [
        1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল',
        5 => 'মে', 6 => 'জুন', 7 => 'জুলাই', 8 => 'আগস্ট',
        9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
    ];
}

function schoolCalendarTypeLabel(string $type): string
{
    return match ($type) {
        'holiday' => 'ছুটি',
        'exam' => 'পরীক্ষা',
        'event' => 'অনুষ্ঠান',
        default => 'অন্যান্য',
    };
}

function publicGalleryAlbums(PDO $db): array
{
    if (!tableExists($db, 'gallery_albums')) {
        return [];
    }

    $albums = fetchRows(
        $db,
        'SELECT * FROM gallery_albums WHERE album_status = 1 ORDER BY album_year DESC, id DESC'
    );
    if ($albums === [] || !tableExists($db, 'image_gallery')) {
        return $albums;
    }

    foreach ($albums as $index => $album) {
        $count = fetchRows(
            $db,
            'SELECT COUNT(*) AS total FROM image_gallery WHERE image_gallery_status = 1 AND album_id = :id',
            ['id' => (int) $album['id']]
        );
        $albums[$index]['photo_count'] = (int) ($count[0]['total'] ?? 0);
        if (trim((string) ($album['cover_file'] ?? '')) === '') {
            $cover = fetchRows(
                $db,
                'SELECT image_gallery_file FROM image_gallery
                 WHERE image_gallery_status = 1 AND album_id = :id
                 ORDER BY id DESC LIMIT 1',
                ['id' => (int) $album['id']]
            );
            $albums[$index]['cover_file'] = (string) ($cover[0]['image_gallery_file'] ?? '');
        }
    }

    return $albums;
}

function publicGalleryAlbum(PDO $db, int $id): ?array
{
    if ($id < 1 || !tableExists($db, 'gallery_albums')) {
        return null;
    }
    $rows = fetchRows($db, 'SELECT * FROM gallery_albums WHERE id = :id AND album_status = 1 LIMIT 1', ['id' => $id]);

    return $rows[0] ?? null;
}

function publicGalleryPhotos(PDO $db, ?int $albumId = null): array
{
    if (!tableExists($db, 'image_gallery')) {
        return [];
    }
    $sql = 'SELECT * FROM image_gallery WHERE image_gallery_status = 1';
    $params = [];
    if ($albumId !== null && $albumId > 0) {
        $sql .= ' AND album_id = :album_id';
        $params['album_id'] = $albumId;
    }
    $sql .= ' ORDER BY image_gallery_creation_time DESC';

    return fetchRows($db, $sql, $params);
}

function publicUngroupedGalleryPhotos(PDO $db): array
{
    if (!tableExists($db, 'image_gallery')) {
        return [];
    }

    return fetchRows(
        $db,
        'SELECT * FROM image_gallery
         WHERE image_gallery_status = 1 AND (album_id IS NULL OR album_id = 0)
         ORDER BY image_gallery_creation_time DESC'
    );
}

function schoolCalendarItemEndDate(array $item): string
{
    $start = (string) ($item['event_date'] ?? '');
    $end = trim((string) ($item['end_date'] ?? ''));
    if ($start === '') {
        return $end;
    }
    if ($end === '' || $end < $start) {
        return $start;
    }

    return $end;
}

function schoolCalendarFillClass(array $types): string
{
    if (isset($types['holiday'])) {
        return ' is-fill-holiday';
    }
    if (isset($types['event'])) {
        return ' is-fill-event';
    }
    if (isset($types['exam'])) {
        return ' is-fill-exam';
    }

    return '';
}

function schoolCalendarDayBlocksExam(array $types, bool $isWeekend = false): bool
{
    return $isWeekend || isset($types['holiday']) || isset($types['event']);
}

function schoolCalendarVisibleDayItems(array $dayItems, bool $isWeekend = false): array
{
    $types = [];
    foreach ($dayItems as $item) {
        $type = (string) ($item['event_type'] ?? '');
        if ($type !== '') {
            $types[$type] = true;
        }
    }
    if (!schoolCalendarDayBlocksExam($types, $isWeekend)) {
        return $dayItems;
    }

    return array_values(array_filter(
        $dayItems,
        static fn (array $item): bool => (string) ($item['event_type'] ?? '') !== 'exam'
    ));
}

function schoolCalendarMonthWorkSummary(int $year, int $month, array $byDay): array
{
    $daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $offDays = 0;
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $weekday = (int) date('w', strtotime(sprintf('%04d-%02d-%02d', $year, $month, $day)));
        $isWeekend = $weekday === 5 || $weekday === 6;
        $types = [];
        foreach ($byDay[$day] ?? [] as $item) {
            $type = (string) ($item['event_type'] ?? '');
            if ($type !== '') {
                $types[$type] = true;
            }
        }
        if ($isWeekend || isset($types['holiday'])) {
            $offDays++;
        }
    }

    return [
        'days_in_month' => $daysInMonth,
        'off_days' => $offDays,
        'working_days' => $daysInMonth - $offDays,
    ];
}

function publicCalendarYears(PDO $db, int $currentYear): array
{
    $years = [$currentYear];
    if (tableExists($db, 'academic_calendar_items')) {
        $rows = fetchRows(
            $db,
            'SELECT YEAR(event_date) AS y
             FROM academic_calendar_items
             WHERE item_status = 1
             UNION
             SELECT YEAR(COALESCE(end_date, event_date)) AS y
             FROM academic_calendar_items
             WHERE item_status = 1'
        );
        foreach ($rows as $row) {
            $year = (int) ($row['y'] ?? 0);
            if ($year >= 2000 && $year <= 2100) {
                $years[] = $year;
            }
        }
    }
    $years = array_values(array_unique($years));
    sort($years);

    return $years;
}

function publicCalendarItems(PDO $db, int $year, int $month = 0): array
{
    if (!tableExists($db, 'academic_calendar_items')) {
        return [];
    }
    if ($month > 0) {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        return fetchRows(
            $db,
            'SELECT * FROM academic_calendar_items
             WHERE item_status = 1
               AND event_date <= :month_end
               AND COALESCE(end_date, event_date) >= :month_start
             ORDER BY event_date ASC, id ASC',
            ['month_start' => $monthStart, 'month_end' => $monthEnd]
        );
    }
    $yearStart = sprintf('%04d-01-01', $year);
    $yearEnd = sprintf('%04d-12-31', $year);

    return fetchRows(
        $db,
        'SELECT * FROM academic_calendar_items
         WHERE item_status = 1
           AND event_date <= :year_end
           AND COALESCE(end_date, event_date) >= :year_start
         ORDER BY event_date ASC, id ASC',
        ['year_start' => $yearStart, 'year_end' => $yearEnd]
    );
}

function meritoriousGroupOptions(): array
{
    return [
        'science' => 'বিজ্ঞান',
        'humanities' => 'মানবিক',
        'business' => 'ব্যবসায়',
    ];
}

function meritoriousNormalizeGroup(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'science', 'sci', 'বিজ্ঞান' => 'science',
        'humanities', 'arts', 'humanity', 'arts/humanities', 'মানবিক' => 'humanities',
        'business', 'commerce', 'business studies', 'bus', 'ব্যবসায়', 'ব্যবসায়' => 'business',
        default => $value,
    };
}

function meritoriousGroupLabel(string $value): string
{
    $key = meritoriousNormalizeGroup($value);
    $options = meritoriousGroupOptions();

    return $options[$key] ?? trim($value);
}

function meritoriousDummyPhoto(): string
{
    return 'image/student_dummy.svg';
}

function meritoriousPhotoUrl(?string $picture): string
{
    $file = publicMediaBasename((string) $picture);
    if ($file === '') {
        return meritoriousDummyPhoto();
    }
    if (is_file(publicSitePath('meritorious_students_file/' . $file))) {
        return schoolMediaUrl($file, 'meritorious_students_file', meritoriousDummyPhoto());
    }

    return meritoriousDummyPhoto();
}

function meritoriousDisplayName(array $row): string
{
    $ban = trim((string) ($row['students_name_ban'] ?? $row['name_ban'] ?? $row['students_name'] ?? ''));
    $eng = trim((string) ($row['students_name_eng'] ?? $row['name_eng'] ?? ''));

    return $ban !== '' ? $ban : $eng;
}

function meritoriousParentName(array $row, string $kind): string
{
    if ($kind === 'mother') {
        $ban = trim((string) ($row['mname_ban'] ?? ''));
        $eng = trim((string) ($row['mname_eng'] ?? ''));

        return $ban !== '' ? $ban : $eng;
    }
    $ban = trim((string) ($row['fname_ban'] ?? $row['fname'] ?? ''));
    $eng = trim((string) ($row['fname_eng'] ?? ''));

    return $ban !== '' ? $ban : $eng;
}

function publicMeritoriousStudents(PDO $db, string $batch = '', string $group = ''): array
{
    if (!tableExists($db, 'meritorious_students')) {
        return [];
    }
    $sql = 'SELECT * FROM meritorious_students WHERE meritorious_students_status = 1';
    $params = [];
    if ($batch !== '') {
        $sql .= ' AND session_year = :batch';
        $params['batch'] = $batch;
    }
    if ($group !== '' && isset(meritoriousGroupOptions()[$group])) {
        $sql .= ' AND section_group = :group';
        $params['group'] = $group;
    }
    $sql .= ' ORDER BY session_year DESC, students_name_ban ASC, students_name_eng ASC, id DESC';

    return fetchRows($db, $sql, $params);
}

function publicMeritoriousBatches(PDO $db): array
{
    if (!tableExists($db, 'meritorious_students')) {
        return [];
    }
    $rows = fetchRows(
        $db,
        "SELECT DISTINCT session_year FROM meritorious_students
         WHERE meritorious_students_status = 1 AND session_year IS NOT NULL AND TRIM(session_year) <> ''
         ORDER BY session_year DESC"
    );

    return array_values(array_filter(array_map(
        static fn (array $row): string => trim((string) $row['session_year']),
        $rows
    )));
}

function publicSyllabusFiles(PDO $db): array
{
    if (!tableExists($db, 'syllabus')) {
        return [];
    }

    return fetchRows($db, 'SELECT * FROM syllabus WHERE syllabus_status = 1 ORDER BY syllabus_creation_time DESC');
}

function publicResultsByRollSession(PDO $db, string $roll, string $sessionYear): array
{
    $roll = publicStudentSearchQuery($roll);
    $sessionYear = trim($sessionYear);
    if ($roll === '' || $sessionYear === '' || !tableExists($db, 'students_info')) {
        return [];
    }
    $students = fetchRows(
        $db,
        'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.student_pic, s.fname_ban, s.mname_ban, s.sections, s.session_year, s.cls
         FROM students_info s'
        . studentsListJoinSql() . '
         WHERE s.status = 1 AND s.session_year = :session_year
           AND (s.cls_roll = :roll OR s.cls_roll = :roll_bn)
         ORDER BY ' . studentsListOrderSql(),
        [
            'session_year' => $sessionYear,
            'roll' => $roll,
            'roll_bn' => bnDigits($roll),
        ]
    );
    if ($students === []) {
        $students = fetchRows(
            $db,
            'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.student_pic, s.fname_ban, s.mname_ban, s.sections, s.session_year, s.cls
             FROM students_info s'
            . studentsListJoinSql() . '
             WHERE s.status = 1 AND s.session_year = :session_year AND CAST(s.cls_roll AS CHAR) = :roll
             ORDER BY ' . studentsListOrderSql(),
            ['session_year' => $sessionYear, 'roll' => $roll]
        );
    }

    $exams = schoolPublishedExams($db);
    $out = [];
    foreach ($students as $student) {
        $classId = (int) ($student['cls'] ?? 0);
        $sectionId = (int) ($student['sections'] ?? 0);
        $sheets = [];
        foreach ($exams as $exam) {
            $sheet = studentResultSheet($db, (string) $student['cls_roll'], $classId, $sectionId, $sessionYear, (int) $exam['id']);
            if (($sheet['rows'] ?? []) === []) {
                continue;
            }
            $sheets[] = [
                'exam' => $exam,
                'sheet' => $sheet,
            ];
        }
        if ($sheets === []) {
            continue;
        }
        $out[] = [
            'student' => $student,
            'exams' => $sheets,
        ];
    }

    return $out;
}

function guardianNotifyRecipients(PDO $db): array
{
    $mobiles = [];
    $emails = [];
    if (tableExists($db, 'students_info')) {
        $rows = fetchRows($db, 'SELECT mobile FROM students_info WHERE status = 1');
        foreach ($rows as $row) {
            $digits = preg_replace('/\D+/', '', (string) ($row['mobile'] ?? '')) ?? '';
            if (strlen($digits) >= 11) {
                $mobiles[$digits] = true;
            }
        }
    }
    $settings = schoolSettings($db);
    foreach (preg_split('/[\s,;]+/', (string) ($settings['notify_parent_emails'] ?? '')) ?: [] as $email) {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = true;
        }
    }
    $fromEmail = trim((string) ($settings['notify_from_email'] ?? ''));
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = trim((string) ($settings['email'] ?? ''));
    }

    return [
        'mobiles' => array_keys($mobiles),
        'emails' => array_keys($emails),
        'from' => filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? $fromEmail : '',
        'sms_url' => trim((string) ($settings['notify_sms_url'] ?? '')),
    ];
}

function notifyGuardiansOfNotice(PDO $db, int $noticeId, string $text): array
{
    require_once __DIR__ . '/sms.php';
    $recipients = guardianNotifyRecipients($db);
    $summary = ['email' => 0, 'sms' => 0, 'failed' => 0];
    $subject = 'নতুন নোটিশ: ' . mb_substr($text, 0, 60, 'UTF-8');
    $body = "বিদ্যালয়ের নতুন নোটিশ:\n\n" . $text . "\n\nওয়েবসাইটে দেখুন।";
    $log = tableExists($db, 'notice_notify_log')
        ? $db->prepare(
            'INSERT INTO notice_notify_log (notice_id, channel, recipient, status, detail)
             VALUES (:notice_id, :channel, :recipient, :status, :detail)'
        )
        : null;

    foreach ($recipients['emails'] as $email) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        if ($recipients['from'] !== '') {
            $headers[] = 'From: ' . $recipients['from'];
        }
        $ok = @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
        $summary[$ok ? 'email' : 'failed']++;
        $log?->execute([
            'notice_id' => $noticeId,
            'channel' => 'email',
            'recipient' => $email,
            'status' => $ok ? 'sent' : 'failed',
            'detail' => $ok ? '' : 'mail() failed',
        ]);
    }

    if (function_exists('smsGatewayReady') && smsGatewayReady($db)) {
        $smsText = mb_substr($text, 0, 300, 'UTF-8');
        foreach ($recipients['mobiles'] as $mobile) {
            $result = sendSchoolSms($db, $mobile, $smsText, 'notice');
            $ok = !empty($result['ok']);
            $summary[$ok ? 'sms' : 'failed']++;
            $log?->execute([
                'notice_id' => $noticeId,
                'channel' => 'sms',
                'recipient' => $mobile,
                'status' => $ok ? 'sent' : 'failed',
                'detail' => (string) ($result['detail'] ?? ''),
            ]);
        }
    }

    return $summary;
}
