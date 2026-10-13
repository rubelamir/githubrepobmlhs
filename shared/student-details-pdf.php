<?php
declare(strict_types=1);

require_once __DIR__ . '/mpdf-bangla.php';

/**
 * @param list<array<string, mixed>> $students
 * @param array<string, mixed> $settings
 */
function outputStudentDetailsListPdf(
    array $students,
    array $settings,
    string $sessionYear,
    string $className,
    string $sectionName,
    string $filename
): void {
    if ($students === []) {
        throw new RuntimeException('No student records are available for the PDF.');
    }

    $schoolName = trim((string) ($settings['school_name_bn'] ?? ''));
    $mpdf = createBanglaMpdf(
        $schoolName . ' - Student Details List',
        $schoolName !== '' ? $schoolName : 'School Admin',
        'A4',
        'P'
    );
    $mpdf->SetMargins(12, 12, 12);
    $mpdf->SetAutoPageBreak(true, 12);
    $mpdf->WriteHTML(studentDetailsListPdfCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML(
        studentDetailsListPdfHtml($students, $settings, $sessionYear, $className, $sectionName),
        \Mpdf\HTMLParserMode::HTML_BODY
    );
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'student-details-list.pdf';
    $mpdf->Output($safeFilename, \Mpdf\Output\Destination::DOWNLOAD);
}

function studentDetailsListPdfCss(): string
{
    return <<<'CSS'
body { font-family: hindsiliguri, sans-serif; color: #17231e; font-size: 9pt; }
.sd-head { width: 100%; border-collapse: collapse; border-bottom: .35mm solid #315849; margin-bottom: 2mm; }
.sd-head td { vertical-align: middle; padding: 0 0 2mm; }
.sd-logo-cell { width: 18mm; }
.sd-logo { width: 15mm; height: 15mm; }
.sd-brand { text-align: center; }
.sd-school { color: #174936; font-size: 14pt; font-weight: bold; }
.sd-meta { color: #374b43; font-size: 7pt; }
.sd-title { margin: 1.5mm 0 2mm; text-align: center; font-size: 9pt; }
.sd-table { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
.sd-table th, .sd-table td { border: .2mm solid #687b72; padding: 1.2mm 1.2mm; vertical-align: top; }
.sd-table thead th { background-color: #174936; color: #ffffff; font-weight: bold; text-align: center; vertical-align: middle; }
.sd-table tbody tr.alt td { background-color: #f3f7f5; }
.sd-table td.center { text-align: center; }
.sd-table th:nth-child(1), .sd-table td:nth-child(1) { width: 15mm; }
.sd-table th:nth-child(2), .sd-table td:nth-child(2) { width: 42mm; }
.sd-table th:nth-child(3), .sd-table td:nth-child(3) { width: 38mm; }
.sd-table th:nth-child(4), .sd-table td:nth-child(4) { width: 42mm; }
.sd-table th:nth-child(5), .sd-table td:nth-child(5) { width: 49mm; }
.sd-roll { font-weight: bold; font-size: 9pt; color: #174936; }
.sd-name { font-weight: bold; font-size: 8.5pt; color: #0b2a1e; }
.sd-line { line-height: 1.35; }
.sd-label { color: #58675f; }
.sd-badge { background-color: #dcece4; color: #0f3d2b; font-weight: bold; }
.sd-badge-warm { background-color: #fdebc8; color: #6b4a00; }
.sd-subhead { font-weight: bold; color: #174936; margin-top: 1mm; }
hr.sd-divider { color: #9db0a7; height: .2mm; margin: 1mm 0; }
.sd-photo { width: 11mm; height: 14mm; }
.sd-address div { line-height: 1.35; word-wrap: break-word; }
CSS;
}

/**
 * @param list<array<string, mixed>> $students
 * @param array<string, mixed> $settings
 */
function studentDetailsListPdfHtml(
    array $students,
    array $settings,
    string $sessionYear,
    string $className,
    string $sectionName
): string {
    $logoPath = mpdfSchoolLogoPath($settings);
    $logo = $logoPath !== '' ? str_replace('\\', '/', $logoPath) : '';
    $address = schoolPlainAddress((string) ($settings['address'] ?? ''));
    $meta = [];
    foreach ([
        'EIIN: ' . trim((string) ($settings['eiin'] ?? '')),
        'Mobile: ' . trim((string) ($settings['mobile'] ?? '')),
        trim((string) ($settings['email'] ?? '')),
    ] as $item) {
        if (trim($item) !== '') {
            $meta[] = $item;
        }
    }
    $html = '<table class="sd-head"><tr><td class="sd-logo-cell">'
        . ($logo !== '' ? '<img class="sd-logo" src="' . h($logo) . '" alt="">' : '')
        . '</td><td class="sd-brand"><div class="sd-school">' . h((string) ($settings['school_name_bn'] ?? '')) . '</div>'
        . ($address !== '' ? '<div class="sd-meta">' . h($address) . '</div>' : '')
        . ($meta !== [] ? '<div class="sd-meta">' . h(implode(' · ', $meta)) . '</div>' : '')
        . '</td><td class="sd-logo-cell"></td></tr></table>';
    $html .= '<div class="sd-title"><strong>Student Details List</strong><br>'
        . 'Session: ' . h($sessionYear) . ' · Class: ' . h($className) . ' · Section: ' . h($sectionName)
        . '</div>';
    $html .= '<table class="sd-table" repeat_header="1"><thead><tr>'
        . '<th>Roll / Photo</th><th>Student</th><th>Parents</th><th>Academic</th><th>Address</th>'
        . '</tr></thead><tbody>';

    foreach ($students as $index => $student) {
        $v = static fn (string $key): string => trim((string) ($student[$key] ?? ''));
        $line = static fn (string $label, string $value): string => $value === '' ? '' :
            '<div class="sd-line"><span class="sd-label">' . h($label) . ':</span> ' . h($value) . '</div>';
        $birthDate = $v('birth_date');
        if ($birthDate !== '' && !str_starts_with($birthDate, '0000-00-00')) {
            $parsedBirthDate = date_create($birthDate);
            $birthDate = $parsedBirthDate instanceof DateTimeInterface ? $parsedBirthDate->format('d-m-Y') : $birthDate;
        } else {
            $birthDate = '';
        }
        $gender = match ($v('gender')) {
            'male' => 'Male',
            'female' => 'Female',
            default => '',
        };
        $scholarshipLabel = match ($v('scholarship_stipend_info')) {
            'scholarship' => 'বৃত্তি প্রাপ্ত',
            'stipend' => 'উপবৃত্তি প্রাপ্ত',
            default => '',
        };
        $addressParts = [
            'বাড়ি' => $v('house_name'),
            'গ্রাম' => $v('village') !== '' ? $v('village') : $v('village_en'),
            'ডাকঘর' => $v('post_office') !== '' ? $v('post_office') : $v('post_office_en'),
            'উপজেলা' => $v('upazila_name'),
            'জেলা' => $v('district_name'),
        ];
        $addressHtml = '';
        foreach ($addressParts as $label => $value) {
            if ($value !== '') {
                $addressHtml .= '<div><strong>' . h($label) . ':</strong> ' . h($value) . '</div>';
            }
        }
        $previous = $line('School', $v('last_school_name')) . $line('Class', $v('last_class')) . $line('Year', $v('passing_year'));
        $academic = '<span class="sd-badge">&nbsp;' . h($v('class_name') !== '' ? $v('class_name') : '—') . '&nbsp;</span> '
            . '<span class="sd-badge">&nbsp;' . h($v('section_name') !== '' ? $v('section_name') : '—') . '&nbsp;</span>'
            . $line('Session', $v('session_year')) . $line('Reg. No.', $v('reg_no'))
            . ($scholarshipLabel !== '' ? '<div><span class="sd-badge sd-badge-warm">&nbsp;' . h($scholarshipLabel) . '&nbsp;</span></div>' : '')
            . ($previous !== '' ? '<hr class="sd-divider"><div class="sd-subhead">Previous School</div>' . $previous : '');
        $photoPath = mpdfStudentPhotoPath($v('student_pic'));
        $photo = $photoPath !== '' ? '<div><img class="sd-photo" src="' . h(str_replace('\\', '/', $photoPath)) . '" alt=""></div>' : '';
        $html .= '<tr' . ($index % 2 === 1 ? ' class="alt"' : '') . '>'
            . '<td class="center"><span class="sd-roll">' . h($v('cls_roll')) . '</span>' . $photo . '</td>'
            . '<td><div class="sd-name">' . h($v('name_eng')) . '</div>'
            . $line('Birth Reg.', $v('birth_reg_no')) . $line('DOB', $birthDate) . $line('Gender', $gender)
            . $line('Religion', $v('religion')) . $line('Mobile', $v('mobile')) . '</td>'
            . '<td>' . $line('Father', $v('fname_eng')) . $line('NID', $v('father_nid_no')) . $line('Profession', $v('fprofession'))
            . '<hr class="sd-divider">'
            . $line('Mother', $v('mname_eng')) . $line('NID', $v('mother_nid_no')) . $line('Profession', $v('mprofession')) . '</td>'
            . '<td>' . $academic . '</td>'
            . '<td class="sd-address">' . $addressHtml . '</td></tr>';
    }

    return $html . '</tbody></table>';
}
