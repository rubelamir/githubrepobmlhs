<?php
declare(strict_types=1);

require_once __DIR__ . '/mpdf-bangla.php';
if (!function_exists('schoolPlainAddress')) {
    require_once dirname(__DIR__) . '/bmlhs/includes/site.php';
}
if (!function_exists('examRoutineDateBn')) {
    require_once __DIR__ . '/academics.php';
}
if (!function_exists('banglaPdfImageSrc')) {
    function banglaPdfImageSrc(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }

        return str_replace('\\', '/', $path);
    }
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<string, mixed> $settings
 */
function outputExamRoutinePdf(
    array $rows,
    array $settings,
    string $examName,
    string $classLine,
    string $sessionYear,
    string $filename
): void {
    if ($rows === []) {
        throw new RuntimeException('এই ফিল্টারে কোনো রুটিন নেই।');
    }

    $mpdf = createBanglaMpdf(
        (string) ($settings['school_name_bn'] ?? 'পরীক্ষার রুটিন'),
        (string) ($settings['school_name_bn'] ?? 'School Admin')
    );
    $mpdf->SetMargins(8, 8, 20);
    $mpdf->SetAutoPageBreak(true, 8);

    $mpdf->WriteHTML(examRoutinePdfCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML(
        examRoutinePdfHtml($rows, $settings, $examName, $classLine, $sessionYear),
        \Mpdf\HTMLParserMode::HTML_BODY
    );

    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'exam-routine.pdf';
    $mpdf->Output($safe, \Mpdf\Output\Destination::DOWNLOAD);
}

function examRoutinePdfCss(): string
{
    return <<<'CSS'
body {
  font-family: hindsiliguri, sans-serif;
  color: #0f2f26;
}
.erp-head {
  width: 100%;
  border-collapse: collapse;
  border-bottom: 0.35mm solid #0f2f26;
}
.erp-head td { vertical-align: middle; padding: 0 0 2.5mm; }
.erp-logo-cell { width: 30mm; text-align: left; }
.erp-logo { width: 28mm; height: 28mm; }
.erp-side { width: 30mm; }
.erp-brand { text-align: center; }
.erp-school {
  font-size: 24pt;
  font-weight: 700;
  line-height: 1.25;
}
.erp-meta {
  font-size: 13pt;
  color: #334155;
  line-height: 1.4;
}
.erp-title {
  margin: 3mm 0 3mm;
  text-align: center;
  font-size: 15pt;
  font-weight: 700;
  background-color: #efe4c8;
  border: 0.3mm solid #c4892a;
  padding: 1.2mm 2mm;
}
.erp-exam {
  text-align: center;
  font-size: 13pt;
  font-weight: 700;
  margin-bottom: 4mm;
}
.routine-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 10.5pt;
}
.routine-table th {
  background-color: #0f2f26;
  color: #ffffff;
  font-weight: 700;
  text-align: center;
  padding: 2mm 1.5mm;
  border: 0.3mm solid #0f2f26;
}
.routine-table td {
  padding: 1.6mm 1.5mm;
  border: 0.3mm solid #0f2f26;
  vertical-align: middle;
}
.routine-table tr.alt td { background-color: #f7f3e8; }
.routine-table td.c { text-align: center; }
.routine-table td.subject { font-weight: 700; }
.routine-sign {
  width: 100%;
  border-collapse: collapse;
  margin-top: 22mm;
  font-size: 11pt;
  font-weight: 700;
}
.routine-sign td.sign {
  width: 45mm;
  text-align: center;
  border-top: 0.3mm solid #0f2f26;
  padding-top: 1mm;
}
CSS;
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<string, mixed> $settings
 */
function examRoutinePdfHtml(
    array $rows,
    array $settings,
    string $examName,
    string $classLine,
    string $sessionYear
): string {
    $logo = banglaPdfImageSrc(mpdfSchoolLogoPath($settings));
    $schoolName = trim((string) ($settings['school_name_bn'] ?? ''));
    $address = schoolPlainAddress((string) ($settings['address'] ?? ''));
    $eiin = trim((string) ($settings['eiin'] ?? ''));
    $mobile = publicStaffMobileDisplay((string) ($settings['mobile'] ?? ''));

    $meta = '';
    if ($eiin !== '') {
        $meta .= 'EIIN: ' . h($eiin);
    }
    if ($eiin !== '' && $mobile !== '') {
        $meta .= ' · ';
    }
    if ($mobile !== '') {
        $meta .= 'মোবাইল: ' . h($mobile);
    }

    $html = '<table class="erp-head"><tr>'
        . '<td class="erp-logo-cell">'
        . ($logo !== '' ? '<img class="erp-logo" src="' . h($logo) . '" alt="">' : '')
        . '</td>'
        . '<td class="erp-brand">'
        . '<div class="erp-school">' . h($schoolName) . '</div>'
        . ($address !== '' ? '<div class="erp-meta">' . h($address) . '</div>' : '')
        . ($meta !== '' ? '<div class="erp-meta">' . $meta . '</div>' : '')
        . '</td>'
        . '<td class="erp-side"></td>'
        . '</tr></table>';

    $examLine = h($examName !== '' ? $examName : 'পরীক্ষা');
    if ($classLine !== '') {
        $examLine .= ' · ' . h($classLine);
    }
    if ($sessionYear !== '') {
        $examLine .= ' · শিক্ষাবর্ষ ' . h(bnDigits($sessionYear));
    }
    $html .= '<div class="erp-title">পরীক্ষার রুটিন / EXAM ROUTINE</div>'
        . '<div class="erp-exam">' . $examLine . '</div>';

    $html .= '<table class="routine-table" repeat_header="1"><thead><tr>'
        . '<th style="width:7%">ক্রম</th>'
        . '<th style="width:15%">তারিখ</th>'
        . '<th style="width:11%">বার</th>'
        . '<th>বিষয়</th>'
        . '<th style="width:22%">সময়</th>'
        . '<th style="width:10%">পূর্ণমান</th>'
        . '<th style="width:10%">কক্ষ</th>'
        . '</tr></thead><tbody>';

    foreach (array_values($rows) as $index => $row) {
        $dateValue = (string) ($row['exam_date'] ?? '');
        $weekday = '';
        $dt = $dateValue !== '' ? date_create($dateValue) : false;
        if ($dt instanceof DateTimeInterface) {
            $weekday = weekDayBangla((int) $dt->format('w'));
        }
        $time = examRoutineTimeLabel((string) ($row['start_time'] ?? ''), (string) ($row['end_time'] ?? ''));
        $fullMarks = trim((string) ($row['full_marks'] ?? ''));
        $room = trim((string) ($row['room'] ?? ''));

        $html .= '<tr' . ($index % 2 === 1 ? ' class="alt"' : '') . '>'
            . '<td class="c">' . h(bnDigits((string) ($index + 1))) . '</td>'
            . '<td class="c">' . h(examRoutineDateBn($dateValue)) . '</td>'
            . '<td class="c">' . h($weekday !== '' ? $weekday : '—') . '</td>'
            . '<td class="subject">' . h((string) ($row['subject_name'] ?? '')) . '</td>'
            . '<td class="c">' . h($time !== '' ? $time : '—') . '</td>'
            . '<td class="c">' . h($fullMarks !== '' ? bnDigits($fullMarks) : '—') . '</td>'
            . '<td class="c">' . h($room !== '' ? $room : '—') . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<table class="routine-sign"><tr>'
        . '<td></td><td class="sign">প্রধান শিক্ষক</td>'
        . '</tr></table>';

    return $html;
}
