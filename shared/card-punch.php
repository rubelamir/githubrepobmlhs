<?php
declare(strict_types=1);

const CARD_PUNCH_REPEAT_SECONDS = 60;

function ensureCardPunchSchema(PDO $db): void
{
    if (tableExists($db, 'students_info')) {
        ensureColumn($db, 'students_info', 'card_no', 'VARCHAR(40) DEFAULT NULL');
        try {
            $db->exec('CREATE INDEX idx_students_card_no ON students_info (card_no)');
        } catch (PDOException $exception) {
            // Index already exists.
        }
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS attendance_punch_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            punched_at DATETIME NOT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'web',
            KEY idx_punch_student (student_id, punched_at),
            KEY idx_punch_time (punched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function normalizeCardNo(string $raw): string
{
    $card = strtoupper(trim($raw));

    return preg_replace('/[^A-Z0-9]/', '', $card) ?? '';
}

function cardPunchDeviceKey(): string
{
    return hash('sha256', 'card-punch|' . appSigningKey());
}

function cardPunchNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka'));
}

/**
 * Marks the student owning the card present for today and logs the punch.
 *
 * @return array{ok:bool,status:string,message:string,student?:array<string,string>,time?:string}
 */
function cardPunchRecord(PDO $db, string $rawCard, string $source = 'web'): array
{
    $card = normalizeCardNo($rawCard);
    if ($card === '') {
        return ['ok' => false, 'status' => 'invalid', 'message' => 'কার্ড নম্বর পাওয়া যায়নি।'];
    }

    $rows = fetchRows(
        $db,
        'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.student_pic, c.class_name_ban, sec.section_name_ban
         FROM students_info s'
        . studentsListJoinSql()
        . ' WHERE s.card_no = :card AND s.status = 1 LIMIT 1',
        ['card' => $card]
    );
    if ($rows === []) {
        return ['ok' => false, 'status' => 'unknown', 'message' => 'এই কার্ড কোনো শিক্ষার্থীর সঙ্গে যুক্ত নয়।'];
    }

    $row = $rows[0];
    $studentId = (int) $row['id'];
    $now = cardPunchNow();
    $info = [
        'id' => (string) $studentId,
        'name' => trim((string) ($row['name_ban'] ?? '')) !== '' ? (string) $row['name_ban'] : (string) ($row['name_eng'] ?? ''),
        'class' => trim((string) ($row['class_name_ban'] ?? '') . ' ' . (string) ($row['section_name_ban'] ?? '')),
        'roll' => (string) ($row['cls_roll'] ?? ''),
        'photo' => studentPhotoUrl((string) ($row['student_pic'] ?? '')),
    ];

    $last = fetchRows(
        $db,
        'SELECT punched_at FROM attendance_punch_log WHERE student_id = :id ORDER BY punched_at DESC LIMIT 1',
        ['id' => $studentId]
    );
    if ($last !== []) {
        $lastAt = new DateTimeImmutable((string) $last[0]['punched_at'], new DateTimeZone('Asia/Dhaka'));
        if ($now->getTimestamp() - $lastAt->getTimestamp() < CARD_PUNCH_REPEAT_SECONDS) {
            return [
                'ok' => true,
                'status' => 'repeat',
                'message' => 'আজকের উপস্থিতি আগেই নেওয়া হয়েছে।',
                'student' => $info,
                'time' => $lastAt->format('h:i A'),
            ];
        }
    }

    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO student_attendance (student_id, attendance_date, status, remarks, marked_by)
             VALUES (:id, :d, \'present\', :remarks, :by)
             ON DUPLICATE KEY UPDATE status = \'present\', marked_by = VALUES(marked_by),
                 remarks = IF(remarks IS NULL OR remarks = \'\', VALUES(remarks), remarks)'
        )->execute([
            'id' => $studentId,
            'd' => $now->format('Y-m-d'),
            'remarks' => 'কার্ড পাঞ্চ ' . $now->format('h:i A'),
            'by' => 'card:' . substr($source, 0, 40),
        ]);
        $db->prepare('INSERT INTO attendance_punch_log (student_id, punched_at, source) VALUES (:id, :t, :s)')
            ->execute(['id' => $studentId, 't' => $now->format('Y-m-d H:i:s'), 's' => substr($source, 0, 40)]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    return [
        'ok' => true,
        'status' => 'present',
        'message' => 'উপস্থিতি নিশ্চিত হয়েছে।',
        'student' => $info,
        'time' => $now->format('h:i A'),
    ];
}
