<?php
declare(strict_types=1);

require_once __DIR__ . '/mpdf-bangla.php';

/**
 * @param list<array<string, mixed>> $sheets Output of studentProfileSheetData().
 */
function outputStudentProfilesPdf(array $sheets, string $filename): void
{
    if ($sheets === []) {
        throw new RuntimeException('No student profiles are available for the PDF.');
    }

    $schoolName = trim((string) ($sheets[0]['school']['school_name_bn'] ?? ''));
    $mpdf = createBanglaMpdf($schoolName . ' - Student Profile', $schoolName !== '' ? $schoolName : 'School Admin', 'A4', 'P');
    $mpdf->SetMargins(12, 12, 12);
    $mpdf->SetAutoPageBreak(true, 12);
    $mpdf->WriteHTML(studentProfilesPdfCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
    foreach ($sheets as $index => $vars) {
        if ($index > 0) {
            $mpdf->AddPage();
        }
        $mpdf->WriteHTML(studentProfilePdfSheetHtml($vars), \Mpdf\HTMLParserMode::HTML_BODY);
    }
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'student-profiles.pdf';
    $mpdf->Output($safeFilename, \Mpdf\Output\Destination::DOWNLOAD);
}

function studentProfilesPdfCss(): string
{
    return <<<'CSS'
body { font-family: hindsiliguri, sans-serif; color: #17231e; font-size: 9pt; }
.sp-head { width: 100%; border-collapse: collapse; border-bottom: .4mm solid #1a4d3e; margin-bottom: 2mm; }
.sp-head td { vertical-align: middle; padding: 0 0 2mm; }
.sp-logo { width: 17mm; height: 17mm; }
.sp-brand { text-align: center; }
.sp-school { color: #174936; font-size: 16pt; font-weight: bold; }
.sp-meta { color: #374b43; font-size: 8pt; }
.sp-photo { width: 24mm; height: 30mm; border: .3mm solid #9db0a7; }
.sp-banner { background-color: #174936; color: #ffffff; text-align: center; padding: 1.5mm; margin: 2mm 0 3mm; }
.sp-banner-title { font-size: 12pt; font-weight: bold; }
.sp-banner-sub { font-size: 9pt; }
.sp-card-title { background-color: #e5ede9; color: #173b2d; font-weight: bold; padding: 1mm 2mm; margin-top: 2mm; border: .2mm solid #9db0a7; }
.sp-fields { width: 100%; border-collapse: collapse; }
.sp-fields th, .sp-fields td { border: .2mm solid #9db0a7; padding: .5mm 2mm; text-align: left; vertical-align: top; }
.sp-fields th { width: 32%; background-color: #f3f7f5; font-weight: bold; color: #35493f; }
.sp-fields th.wide { width: 18%; }
.sp-foot { width: 100%; margin-top: 8mm; border-collapse: collapse; }
.sp-foot td { width: 33%; text-align: center; font-size: 9pt; padding-top: 1mm; }
.sp-line { border-top: .3mm solid #333; width: 45mm; margin: 0 auto 1mm; }
CSS;
}

/** @param array<string, mixed> $vars */
function studentProfilePdfSheetHtml(array $vars): string
{
    $row = $vars['row'];
    $school = $vars['school'];
    $display = $vars['display'];
    $fields = static function (array $pairs) use ($display): string {
        $html = '<table class="sp-fields">';
        foreach ($pairs as $label => $value) {
            $html .= '<tr><th>' . h((string) $label) . '</th><td>' . h((string) $value) . '</td></tr>';
        }

        return $html . '</table>';
    };
    $card = static fn (string $title, string $body): string => '<div class="sp-card-title">' . h($title) . '</div>' . $body;

    $logoPath = mpdfSchoolLogoPath($school);
    $logo = $logoPath !== '' ? '<img class="sp-logo" src="' . h(str_replace('\\', '/', $logoPath)) . '" alt="">' : '';
    $photoPath = mpdfStudentPhotoPath((string) ($row['student_pic'] ?? ''));
    $photo = $photoPath !== '' ? '<img class="sp-photo" src="' . h(str_replace('\\', '/', $photoPath)) . '" alt="">' : '';
    $meta = [];
    foreach ([
        'স্থাপিত: ' . trim((string) ($school['established'] ?? '')),
        'ইআইআইএন: ' . bnDigits(trim((string) ($school['eiin'] ?? ''))),
        'মোবাইল: ' . bnDigits(trim((string) ($vars['schoolMobileDisplay'] ?? ''))),
        trim((string) ($school['email'] ?? '')),
    ] as $item) {
        if (!preg_match('/:\s*$/u', $item) && trim($item) !== '') {
            $meta[] = $item;
        }
    }

    $html = '<table class="sp-head"><tr><td style="width:20mm">' . $logo . '</td><td class="sp-brand">'
        . '<div class="sp-school">' . h((string) ($school['school_name_bn'] ?? '')) . '</div>'
        . '<div class="sp-meta">' . h((string) ($school['school_name_en'] ?? '')) . '</div>'
        . '<div class="sp-meta">' . h((string) ($vars['address'] ?? '')) . '</div>'
        . '<div class="sp-meta">' . h(implode(' · ', $meta)) . '</div>'
        . '</td><td style="width:26mm; text-align:right">' . $photo . '</td></tr></table>';

    $sub = 'শিক্ষাবর্ষ ' . $vars['sessionLabel'] . ' · ' . $vars['classLabel'] . ' শ্রেণী'
        . ($vars['sectionLabel'] !== '—' ? ' · শাখা ' . $vars['sectionLabel'] : '')
        . ($vars['rollLabel'] !== '—' ? ' · রোল ' . $vars['rollLabel'] : '');
    $html .= '<div class="sp-banner"><div class="sp-banner-title">' . h((string) $vars['sheetTitle']) . '</div>'
        . '<div class="sp-banner-sub">' . h($sub) . '</div></div>';

    $academic = [
        'শ্রেণী' => $vars['classLabel'],
        'শাখা' => $vars['sectionLabel'],
        'রোল' => $vars['rollLabel'],
        'শিক্ষাবর্ষ' => $vars['sessionLabel'],
        'রেজি: নং' => $display($row['reg_no'] ?? '', true),
    ];
    if (in_array((string) ($row['scholarship_stipend_info'] ?? ''), ['scholarship', 'stipend'], true)) {
        $academic['বৃত্তি / উপবৃত্তি'] = $vars['scholarshipLabel'];
    }
    $academic['ভর্তি টোকেন'] = $display($row['admission_token'] ?? '', true);
    $html .= $card('একাডেমিক তথ্য', $fields($academic));

    $html .= $card('ব্যক্তিগত তথ্য', $fields([
        'নাম (বাংলা)' => $display($row['name_ban'] ?? ''),
        'নাম (ইংরেজি)' => $display($row['name_eng'] ?? ''),
        'লিঙ্গ' => $display($vars['genderLabel']),
        'ধর্ম' => $display($vars['religionLabel']),
        'জন্ম তারিখ' => $display($vars['birthDisplay'], true),
        'জন্ম সনদ নং' => $display($row['birth_reg_no'] ?? '', true),
        'মোবাইল' => $vars['mobileLabel'],
    ]));

    $html .= $card('পিতা-মাতার তথ্য', $fields([
        'পিতার নাম (বাংলা)' => $display($row['fname_ban'] ?? ''),
        'পিতার নাম (ইংরেজি)' => $display($row['fname_eng'] ?? ''),
        'পিতার এনআইডি' => $display($row['father_nid_no'] ?? '', true),
        'পিতার পেশা' => $display($row['fprofession'] ?? ''),
        'মাতার নাম (বাংলা)' => $display($row['mname_ban'] ?? ''),
        'মাতার নাম (ইংরেজি)' => $display($row['mname_eng'] ?? ''),
        'মাতার এনআইডি' => $display($row['mother_nid_no'] ?? '', true),
        'মাতার পেশা' => $display($row['mprofession'] ?? ''),
    ]));

    $html .= $card('পূর্বের বিদ্যালয়', $fields([
        'অধ্যয়নকৃত শ্রেণী' => $display($row['last_class'] ?? ''),
        'শিক্ষাবর্ষ' => $display($row['passing_year'] ?? '', true),
        'বিদ্যালয়ের নাম' => $display($row['last_school_name'] ?? ''),
    ]));

    $village = trim((string) ($row['village'] ?? '')) !== '' ? $row['village'] : ($row['village_en'] ?? '');
    $postOffice = trim((string) ($row['post_office'] ?? '')) !== '' ? $row['post_office'] : ($row['post_office_en'] ?? '');
    $html .= $card('স্থায়ী ঠিকানা', $fields([
        'বাড়ি / বাসা' => $display($row['house_name'] ?? '', true),
        'গ্রাম' => $display($village),
        'ডাকঘর' => $display($postOffice),
        'উপজেলা' => $display($row['upazilla'] ?? ''),
        'জেলা' => $display($row['district'] ?? ''),
    ]));

    return $html . '<table class="sp-foot"><tr><td><div class="sp-line"></div>অভিভাবকের স্বাক্ষর</td>'
        . '<td>মুদ্রণ: ' . h(bnDigits(date('d-m-Y'))) . '</td>'
        . '<td><div class="sp-line"></div>প্রধান শিক্ষক</td></tr></table>';
}
