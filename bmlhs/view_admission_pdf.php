<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/site.php';
require_once dirname(__DIR__) . '/shared/mpdf-bangla.php';

$db = publicDatabase();
$school = currentSchool();
$cls = trim((string) ($_GET['cls'] ?? ''));
$gender = trim((string) ($_GET['gender'] ?? ''));
$allowedGender = ['male', 'female', 'all'];

if ($cls === '' || !in_array($gender, $allowedGender, true)) {
    http_response_code(400);
    exit('শ্রেণী ও ছাত্র/ছাত্রী নির্বাচন করুন।');
}

$classExists = false;
$className = '';
foreach (admissionClasses($db) as $class) {
    if ((string) ($class['id'] ?? '') === $cls) {
        $classExists = true;
        $className = (string) ($class['class_name_ban'] ?? '');
        break;
    }
}
if (!$classExists) {
    http_response_code(404);
    exit('নির্বাচিত শ্রেণী পাওয়া যায়নি।');
}

$orderSql = studentsListOrderSql('students_info', 'cls', 'sections');
if ($gender === 'all') {
    $sql = "SELECT students_info.*, cls.class_name_ban
            FROM students_info
            JOIN cls ON students_info.cls = cls.id
            LEFT JOIN sections ON CAST(students_info.sections AS CHAR) = CAST(sections.id AS CHAR)
            WHERE students_info.cls = ? AND (students_info.sections = 'others' OR ((students_info.sections IS NULL OR students_info.sections = '') AND students_info.status = 0))
            ORDER BY {$orderSql}";
    $statement = $db->prepare($sql);
    $statement->execute([$cls]);
} else {
    $sql = "SELECT students_info.*, cls.class_name_ban
            FROM students_info
            JOIN cls ON students_info.cls = cls.id
            LEFT JOIN sections ON CAST(students_info.sections AS CHAR) = CAST(sections.id AS CHAR)
            WHERE students_info.cls = ? AND students_info.gender = ? AND (students_info.sections = 'others' OR ((students_info.sections IS NULL OR students_info.sections = '') AND students_info.status = 0))
            ORDER BY {$orderSql}";
    $statement = $db->prepare($sql);
    $statement->execute([$cls, $gender]);
}
$rows = $statement->fetchAll();
if ($rows === []) {
    http_response_code(404);
    exit('এই শর্তে কোনো আবেদন পাওয়া যায়নি।');
}

$genderTitle = $gender === 'male' ? 'ছাত্র' : ($gender === 'female' ? 'ছাত্রী' : 'সকল');
$subtitle = 'প্রাথমিক আবেদন তালিকা — ' . $className . ' · ' . $genderTitle;
$bodyRows = '';
foreach ($rows as $index => $row) {
    $photoPath = mpdfStudentPhotoPath(isset($row['student_pic']) ? (string) $row['student_pic'] : null);
    $photoHtml = $photoPath !== ''
        ? '<img src="' . h(str_replace('\\', '/', $photoPath)) . '" alt="">'
        : '—';
    $birth = publicStaffDate($row['birth_date'] ?? null);
    $genderLabel = (($row['gender'] ?? '') === 'male')
        ? 'ছাত্র'
        : ((($row['gender'] ?? '') === 'female') ? 'ছাত্রী' : '—');
    $mobile = publicStaffMobileDisplay((string) ($row['mobile'] ?? ''));
    $bodyRows .= '<tr>'
        . '<td class="c">' . mpdfClearBnDigitsHtml(h(bnDigits((string) ($index + 1)))) . '</td>'
        . '<td>' . h((string) ($row['class_name_ban'] ?? '')) . '</td>'
        . '<td>' . h((string) ($row['name_ban'] ?? '')) . '</td>'
        . '<td>' . h((string) ($row['fname_ban'] ?? '')) . '</td>'
        . '<td>' . h((string) ($row['mname_ban'] ?? '')) . '</td>'
        . '<td class="c">' . ($birth !== '' ? mpdfClearBnDigitsHtml(h(bnDigits($birth))) : '—') . '</td>'
        . '<td class="c">' . h($genderLabel) . '</td>'
        . '<td class="c">' . mpdfClearBnDigitsHtml(h(bnDigits($mobile))) . '</td>'
        . '<td class="photo">' . $photoHtml . '</td>'
        . '</tr>';
}

$html = mpdfSchoolPadHtml($school)
    . '<h2 class="adm-pdf-title">' . h($subtitle) . '</h2>'
    . '<table class="adm-pdf-table">'
    . '<thead><tr>'
    . '<th class="w-sm">#</th><th class="w-cls">শ্রেণী</th><th>নাম</th><th>পিতার নাম</th><th>মাতার নাম</th>'
    . '<th class="w-date">জন্ম তারিখ</th><th class="w-gen">ছাত্র/ছাত্রী</th><th class="w-mob">মোবাইল</th><th class="w-photo">ছবি</th>'
    . '</tr></thead><tbody>' . $bodyRows . '</tbody></table>';

$mpdf = createBanglaMpdf($subtitle, (string) ($school['school_name_bn'] ?? 'School'), 'A4', 'L');
$mpdf->SetMargins(8, 8, 8);
$mpdf->SetAutoPageBreak(true, 8);
$mpdf->WriteHTML(
    mpdfSchoolPadCss()
    . 'h2.adm-pdf-title { text-align: center; font-size: 12pt; margin: 0 0 3mm; color: #0f2f26; }'
    . '.adm-pdf-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; }'
    . '.adm-pdf-table th, .adm-pdf-table td { border: 0.25mm solid #c9d8d0; padding: 1.4mm 1.6mm; vertical-align: middle; }'
    . '.adm-pdf-table thead th { background: #eef5f1; color: #0f2f26; font-weight: bold; }'
    . '.adm-pdf-table td.c { text-align: center; }'
    . '.adm-pdf-table th.w-sm { width: 9mm; } .adm-pdf-table th.w-cls { width: 20mm; } .adm-pdf-table th.w-date { width: 24mm; } .adm-pdf-table th.w-gen { width: 20mm; } .adm-pdf-table th.w-mob { width: 27mm; } .adm-pdf-table th.w-photo { width: 14mm; }'
    . '.adm-pdf-table td.photo { width: 14mm; text-align: center; }'
    . '.adm-pdf-table td.photo img { width: 11mm; height: 13mm; }',
    \Mpdf\HTMLParserMode::HEADER_CSS
);
$mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

$safeClass = preg_replace('/[^0-9A-Za-z_-]+/', '', $cls) ?: 'class';
$filename = 'admission-applicants-' . $safeClass . '-' . $gender . '.pdf';
$inline = ($_GET['inline'] ?? '') === '1';
$mpdf->Output($filename, $inline ? \Mpdf\Output\Destination::INLINE : \Mpdf\Output\Destination::DOWNLOAD);
