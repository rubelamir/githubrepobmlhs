<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/student-import.php';

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$sections = [
    ['id' => 10, 'section_name' => 'Science', 'section_name_ban' => 'বিজ্ঞান'],
    ['id' => 11, 'section_name' => 'Humanities', 'section_name_ban' => 'মানবিক'],
    ['id' => 12, 'section_name' => 'Business Studies', 'section_name_ban' => 'ব্যবসায় শিক্ষা'],
];

$check(studentImportCanonicalHeader('father_eng') === 'fname_eng', 'father_eng is the English father name');
$check(studentImportCanonicalHeader('mother_eng') === 'mname_eng', 'mother_eng is the English mother name');
$check(str_contains(studentImportTemplateCsv(), 'father_eng') && str_contains(studentImportTemplateCsv(), 'mother_eng') && str_contains(studentImportTemplateCsv(), 'last_school_name'), 'the sample file includes the admission form columns');
$check(studentImportCanonicalHeader('Registration No.') === 'reg_no', 'an English registration heading is recognized');
$check(studentImportCanonicalHeader('রেজিঃ নং') === 'reg_no', 'a Bangla registration heading is recognized');
$check(studentImportCanonicalHeader('নাম') === 'name_ban', 'a Bangla name heading is recognized');
$check(studentImportNormalizeRegNo('2511 861724') === '2511861724', 'spaces inside a registration number are ignored');
$check(studentImportNormalizeRegNo('2.511861724E+09') === '', 'scientific notation is not treated as a registration number');
$check(studentImportNormalizeGender('ছাত্রী') === 'female' && studentImportNormalizeGender('Male') === 'male', 'gender words become male or female');
$check(studentImportNormalizeReligion('ইসলাম') === 'Islam', 'Islam is recognized in Bangla');
$check(studentImportNormalizeReligion('হিন্দু') === 'Hindu', 'Hindu is recognized in Bangla');
$check(studentImportNormalizeReligion('বৌদ্ধ') === 'Buddhist', 'Buddhist is recognized in Bangla');
$check(studentImportNormalizeReligion('খ্রিস্টান') === 'Christian', 'Christian is recognized in Bangla');
$check(studentImportNormalizeBirthDate('25569') === '1970-01-01', 'an Excel date serial becomes a calendar date');
$check(studentImportNormalizeBirthDate('01/05/2009') === '2009-05-01', 'a day/month/year date is kept in that order');
$check(studentImportNormalizeBirthDate('') === '', 'a blank birth date stays blank');
$check(studentImportNormalizeBirthDate('not-a-date') === null, 'a broken birth date is rejected');
$check(studentImportIsClassNine(['class_name' => 'Nine', 'class_name_ban' => '৯ম']), 'class Nine is the class 9 row');
$check(!studentImportIsClassNine(['class_name' => 'Ten', 'class_name_ban' => '১০ম']), 'class Ten is not class 9');

$matrix = [
    ['SL', 'Registration no.', 'নাম', 'Gender', 'ধর্ম', 'Roll', 'শাখা', 'Mobile'],
    ['1', '2511861724', 'করিম', 'ছাত্র', 'ইসলাম', '12', 'বিজ্ঞান', '01711111111'],
    ['2', '2511861725', 'রিনা', 'ছাত্রী', 'হিন্দু', '13', 'Commerce', ''],
    ['3', '2511861724', 'অন্য', 'male', 'Islam', '14', 'Science', '01711111112'],
    ['4', '2511861799', 'ছায়া', 'female', 'Buddhist', '12', 'বিজ্ঞান', ''],
];
$photos = [
    ['stored' => '1_2511861724.jpeg', 'label' => '2511861724.jpeg'],
    ['stored' => '2_2511861725.jpg', 'label' => '2511861725.jpg'],
    ['stored' => '3_2511861724.jpeg', 'label' => 'folder/2511861724.jpeg'],
    ['stored' => '4_notes.png', 'label' => 'notes.png'],
];
$preview = studentImportBuildPreview($matrix, $photos, $sections, ['2511861799' => 'ছায়া'], [
    ['section_id' => 12, 'roll' => '13'],
]);

$byReg = [];
foreach ($preview['rows'] as $row) {
    $byReg[$row['reg_no']][] = $row;
}
$check(count($byReg['2511861724']) === 2, 'both rows of a repeated registration number stay in the preview');
$check(
    in_array('একই রেজিস্ট্রেশন নম্বর ফাইলে একাধিকবার আছে।', $byReg['2511861724'][0]['errors'], true)
    && in_array('একই রেজিস্ট্রেশন নম্বরের একাধিক ছবি আছে।', $byReg['2511861724'][0]['errors'], true),
    'a repeated registration number and two photos block that student'
);
$rina = $byReg['2511861725'][0];
$check($rina['section_id'] === 12 && $rina['religion'] === 'Hindu' && $rina['gender'] === 'female', 'commerce, Hindu, and female match the school values');
$check(in_array('এই শাখায় রোল 13 ইতিমধ্যে একজন ভর্তি শিক্ষার্থীর আছে।', $rina['errors'], true), 'a roll already used in that section is blocked');
$check(in_array('ইংরেজি নাম বাংলা নাম থেকে নেওয়া হয়েছে।', $rina['warnings'], true), 'a missing English name is copied and shown');
$chaya = $byReg['2511861799'][0];
$check(in_array('এই রেজিস্ট্রেশন নম্বর ইতিমধ্যে আছে: ছায়া।', $chaya['errors'], true), 'a registration number already on file is blocked');
$check(in_array('ছবি নেই। ফাইলের নাম 2511861799.jpeg হতে হবে।', $chaya['errors'], true), 'a student without a matching photo is blocked');
$check(in_array('একই শাখায় এই রোল ফাইলে একাধিকবার আছে।', $chaya['errors'], true), 'the same roll twice in one section is blocked');
$check($preview['blocking'] === 4 && $preview['ready'] === 0, 'every broken row blocks saving');
$check(in_array('notes.png', $preview['unrecognized_photos'], true), 'a photo whose name is not a registration number is listed');

$readyMatrix = [
    ['registration_no', 'name_eng', 'gender', 'religion', 'roll', 'section'],
    ['2511000001', 'Karim', 'male', 'Islam', '1', 'Science'],
];
$ready = studentImportBuildPreview($readyMatrix, [
    ['stored' => '1_2511000001.jpeg', 'label' => '2511000001.jpeg'],
], $sections, [], []);
$withParents = studentImportBuildPreview([
    ['registration_no', 'name_ban', 'father_ban', 'father_eng', 'mother_ban', 'mother_eng', 'gender', 'religion', 'roll', 'section', 'district', 'village'],
    ['2511000001', 'করিম', 'আব্দুল', 'Abdul Karim', 'ফাতেমা', 'Fatema', 'male', 'Islam', '1', 'Science', 'Natore', 'ধর্মপুর'],
], [
    ['stored' => '1_2511000001.jpeg', 'label' => '2511000001.jpeg'],
], $sections, [], [], null, [
    'divisions' => [['id' => 2, 'name' => 'Rajshahi', 'bn_name' => 'রাজশাহী']],
    'districts' => [['id' => 7, 'division_id' => 2, 'name' => 'Natore', 'bn_name' => 'নাটোর']],
    'upazilas' => [],
]);
$check(
    $withParents['ready'] === 1
    && $withParents['rows'][0]['fname_eng'] === 'Abdul Karim'
    && $withParents['rows'][0]['mname_eng'] === 'Fatema'
    && $withParents['rows'][0]['district_id'] === 7
    && $withParents['rows'][0]['division_id'] === 2
    && $withParents['rows'][0]['village'] === 'ধর্মপুর',
    'father_eng, mother_eng, district, and village are kept from the spreadsheet'
);
$check($ready['ready'] === 1 && $ready['blocking'] === 0, 'a complete row with one photo is ready to save');
$check($ready['rows'][0]['name_ban'] === 'Karim' && $ready['rows'][0]['section_id'] === 10, 'a single English name fills the Bangla name and science matches');

$work = sys_get_temp_dir() . '/bmlhs-import-test-' . bin2hex(random_bytes(4));
mkdir($work);
try {
    $xlsx = $work . '/class.xlsx';
    $zip = new ZipArchive();
    $check($zip->open($xlsx, ZipArchive::CREATE) === true, 'a sample workbook can be created');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Nine" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Registration no.</t></si><si><t>নাম</t></si></sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="inlineStr"><is><t>Gender</t></is></c><c r="D1" t="inlineStr"><is><t>Religion</t></is></c><c r="E1" t="inlineStr"><is><t>Roll</t></is></c><c r="F1" t="inlineStr"><is><t>Section</t></is></c></row>'
        . '<row r="2"><c r="A2"><v>2511861724</v></c><c r="B2" t="inlineStr"><is><t>করিম</t></is></c><c r="C2" t="inlineStr"><is><t>male</t></is></c><c r="D2" t="inlineStr"><is><t>Islam</t></is></c><c r="E2"><v>4</v></c><c r="F2" t="inlineStr"><is><t>মানবিক</t></is></c></row>'
        . '</sheetData></worksheet>');
    $zip->close();
    $sheet = studentImportReadSpreadsheet($xlsx, 'class.xlsx');
    $fromXlsx = studentImportBuildPreview($sheet, [
        ['stored' => '1_2511861724.jpeg', 'label' => '2511861724.jpeg'],
    ], $sections, [], []);
    $check($fromXlsx['ready'] === 1 && $fromXlsx['rows'][0]['name_ban'] === 'করিম' && $fromXlsx['rows'][0]['section_id'] === 11, 'an xlsx registration list matches the humanities section');

    $csvPath = $work . '/class.csv';
    file_put_contents($csvPath, "\xEF\xBB\xBFregistration_no,name_ban,gender,religion,roll,section\n2511000008,রিনা,female,Christian,2,Business Studies\n");
    $csvRows = studentImportReadSpreadsheet($csvPath, 'class.csv');
    $fromCsv = studentImportBuildPreview($csvRows, [
        ['stored' => '1_2511000008.jpeg', 'label' => '2511000008.jpeg'],
    ], $sections, [], []);
    $check($fromCsv['ready'] === 1 && $fromCsv['rows'][0]['religion'] === 'Christian' && $fromCsv['rows'][0]['section_id'] === 12, 'a UTF-8 CSV matches business studies');
$banglaBusiness = studentImportResolveSection('ব্যবসায় শিক্ষা', studentImportSectionIndex($sections));
$check($banglaBusiness['id'] === 12 && $banglaBusiness['error'] === '', 'the Bangla business-studies name matches that section');

    $image = imagecreatetruecolor(400, 220);
    $red = imagecolorallocate($image, 180, 40, 40);
    imagefilledrectangle($image, 0, 0, 399, 219, $red);
    $source = $work . '/wide.png';
    imagepng($image, $source);
    imagedestroy($image);
    $jpeg = studentImportPassportJpeg($source);
    $info = getimagesizefromstring($jpeg);
    $check(is_array($info) && $info[0] === 108 && $info[1] === 137 && strlen($jpeg) <= 100 * 1024, 'a wide photo is cropped to a 108×137 JPEG under 100KB');
    $check(str_starts_with($jpeg, "\xFF\xD8\xFF"), 'the cropped photo is a JPEG');

    $photoZip = $work . '/photos.zip';
    $zip = new ZipArchive();
    $zip->open($photoZip, ZipArchive::CREATE);
    $zip->addFromString('nested/' . '2511861724.jpeg', $jpeg);
    $zip->addFromString('../escape.jpeg', $jpeg);
    $zip->close();
    $escaped = false;
    try {
        studentImportExtractZip($photoZip, $work . '/out');
    } catch (RuntimeException $exception) {
        $escaped = str_contains($exception->getMessage(), 'অনিরাপদ');
    }
    $check($escaped, 'a zip that tries to leave its folder is rejected');
    $check(!is_file($work . '/escape.jpeg'), 'the rejected zip does not write a file outside the folder');

    $safeZip = $work . '/safe.zip';
    $zip = new ZipArchive();
    $zip->open($safeZip, ZipArchive::CREATE);
    $zip->addFromString('album/2511861724.jpeg', $jpeg);
    $zip->close();
    $extracted = studentImportExtractZip($safeZip, $work . '/safe');
    $check(count($extracted) === 1 && $extracted[0]['label'] === '2511861724.jpeg' && is_file($work . '/safe/' . $extracted[0]['stored']), 'a photo inside a folder is kept under its registration number');
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $remove($path . '/' . $item);
            }
            rmdir($path);
            return;
        }
        if (is_file($path)) {
            unlink($path);
        }
    };
    $remove($work);
}

$schema = file_get_contents(__DIR__ . '/../../shared/schema.php');
$early = strpos($schema, 'ensureStudentRegistrationColumn($db);');
$versionGate = strpos($schema, 'if (schemaVersionIsCurrent($db))');
$check(is_string($schema) && $early !== false && $versionGate !== false && $early < $versionGate, 'registration numbers are added even when the schema version is already current');
$page = file_get_contents(__DIR__ . '/../../dist/pages/student-import.php');
$auth = file_get_contents(__DIR__ . '/../../dist/includes/auth.php');
$sidebar = file_get_contents(__DIR__ . '/../../dist/includes/sidebar.php');
$check(is_string($page) && str_contains($page, 'studentImportCommit'), 'the import page saves only after the preview');
$check(is_string($auth) && str_contains($auth, "'student-import' => 'admissions'"), 'only admission staff can open the import page');
$check(is_string($sidebar) && str_contains($sidebar, 'pages/student-import.php'), 'the student menu links to the import page');

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

fwrite(STDOUT, "ok\n");
