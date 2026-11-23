<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/shared/student-import.php';

$uploadBytes = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && $_POST === []
    && $_FILES === []
    && $uploadBytes > 0
) {
    if (!isAuthenticated()) {
        header('Location: ../examples/login-v2.php', true, 302);
        exit;
    }
    redirectWithMessage('student-import.php', 'danger', 'ফাইল দুটি একসাথে সার্ভারের আপলোড সীমার চেয়ে বড়।');
}

requireAuthentication('../examples/login-v2.php');
set_time_limit(180);

$db = getDatabaseConnection();
$teacher = getAuthenticatedTeacher();
if (!canManageModule('admissions', $teacher)) {
    denyPanelAccess();
}
$basePath = '../';
$pageTitle = 'এক্সেল ও ছবি | School Admin';
$teacherId = (int) ($teacher['id'] ?? 0);
$classes = schoolClasses($db);
$classSectionHints = [];
foreach ($classes as $class) {
    $classId = (int) ($class['id'] ?? 0);
    if ($classId < 1) {
        continue;
    }
    $labels = [];
    foreach (schoolSections($db, $classId) as $section) {
        $label = trim((string) ($section['section_name_ban'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($section['section_name'] ?? ''));
        }
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    $classSectionHints[$classId] = $labels;
}
$sessionYear = (string) date('Y');
$sessionYears = [];
for ($year = (int) date('Y') + 1; $year >= 2015; $year--) {
    $sessionYears[] = (string) $year;
}
$sheetLimit = 5 * 1024 * 1024;
$zipLimit = 40 * 1024 * 1024;
$serverLimit = studentImportIniBytes((string) ini_get('upload_max_filesize'));
if ($serverLimit > 0) {
    $zipLimit = min($zipLimit, $serverLimit);
}
$photosPossible = class_exists(ZipArchive::class) && function_exists('imagecreatefromstring') && function_exists('imagejpeg');

$locations = studentImportLocationCatalog($db);
$loadPreview = static function () use ($db, $teacherId, $classes, $locations): array {
    $token = (string) ($_SESSION['student_import_token'] ?? '');
    $directory = studentImportStagingDirectory($token);
    $manifest = studentImportReadManifest($directory);
    if ((int) ($manifest['teacher_id'] ?? 0) !== $teacherId) {
        throw new RuntimeException('এই আপলোড আর সক্রিয় নেই। আবার ফাইল দিন।');
    }
    $classId = (int) ($manifest['class_id'] ?? 0);
    $session = trim((string) ($manifest['session_year'] ?? ''));
    $class = null;
    foreach ($classes as $item) {
        if ((int) $item['id'] === $classId) {
            $class = $item;
            break;
        }
    }
    if ($class === null) {
        throw new RuntimeException('বাছাই করা শ্রেণি আর নেই। আবার ফাইল দিন।');
    }
    $sections = schoolSections($db, $classId);
    $sheetPath = $directory . DIRECTORY_SEPARATOR . basename((string) ($manifest['spreadsheet'] ?? ''));
    $matrix = studentImportReadSpreadsheet($sheetPath, (string) ($manifest['original_name'] ?? 'sheet.xlsx'));
    $photoList = json_decode((string) file_get_contents($directory . DIRECTORY_SEPARATOR . 'photos.json'), true);
    if (!is_array($photoList)) {
        throw new RuntimeException('ছবির তালিকা পড়া যায়নি। আবার ফাইল দিন।');
    }
    $photoDirectory = $directory . DIRECTORY_SEPARATOR . 'photos';
    $preview = studentImportBuildPreview(
        $matrix,
        $photoList,
        $sections,
        studentImportExistingRegistrations($db),
        studentImportExistingRolls($db, $classId, $session),
        static function (string $stored) use ($photoDirectory): string {
            try {
                studentImportPassportJpeg(studentImportConfinedPhoto($photoDirectory, $stored));
            } catch (Throwable $exception) {
                $message = $exception instanceof RuntimeException ? $exception->getMessage() : 'ছবি প্রস্তুত করা যায়নি।';

                return $message . ' (' . $stored . ')';
            }

            return '';
        },
        $locations
    );

    return [
        'token' => $token,
        'directory' => $directory,
        'class' => $class,
        'session' => $session,
        'preview' => $preview,
        'photo_directory' => $photoDirectory,
    ];
};

if (($_GET['download'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="class-students-template.csv"');
    echo studentImportTemplateCsv();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'discard') {
            $token = (string) ($_POST['import_token'] ?? '');
            if ($token !== '' && $token === (string) ($_SESSION['student_import_token'] ?? '')) {
                studentImportDeleteTree(studentImportStagingRoot() . DIRECTORY_SEPARATOR . $token);
                unset($_SESSION['student_import_token']);
            }
            redirectWithMessage('student-import.php', 'success', 'আপলোড বাতিল করা হয়েছে।');
        }
        if ($action === 'commit') {
            $bundle = $loadPreview();
            if ((string) $bundle['token'] !== (string) ($_POST['import_token'] ?? '')) {
                throw new RuntimeException('এই আপলোড আর সক্রিয় নেই। আবার ফাইল দিন।');
            }
            $preview = $bundle['preview'];
            if (($preview['blocking'] ?? 1) > 0) {
                throw new RuntimeException('ত্রুটি থাকলে ভর্তি করা যাবে না। ফাইল ঠিক করে আবার আপলোড করুন।');
            }
            $count = studentImportCommit(
                $db,
                (int) $bundle['class']['id'],
                (string) $bundle['session'],
                $preview['rows'],
                (string) $bundle['photo_directory']
            );
            studentImportDeleteTree((string) $bundle['directory']);
            unset($_SESSION['student_import_token']);
            $classLabel = trim((string) $bundle['class']['class_name_ban'] . ' / ' . (string) $bundle['class']['class_name']);
            redirectWithMessage(
                'student-import.php',
                'success',
                $count . ' জন শিক্ষার্থী ' . $classLabel . ' (' . $bundle['session'] . ') শ্রেণিতে যোগ হয়েছে। পাসওয়ার্ড মোবাইলের শেষ ৬ সংখ্যা, মোবাইল না থাকলে 123456।'
            );
        }
        if (!$photosPossible) {
            throw new RuntimeException('সার্ভারে Zip বা ছবি প্রস্তুত করার ব্যবস্থা নেই।');
        }
        $classId = (int) ($_POST['cls'] ?? 0);
        $chosenSession = trim((string) ($_POST['session_year'] ?? ''));
        $classOk = false;
        foreach ($classes as $class) {
            if ((int) $class['id'] === $classId) {
                $classOk = true;
                break;
            }
        }
        if (!$classOk || preg_match('/^\d{4}$/', $chosenSession) !== 1) {
            throw new RuntimeException('শ্রেণি ও শিক্ষাবর্ষ বাছুন।');
        }
        if (schoolSections($db, $classId) === []) {
            throw new RuntimeException('এই শ্রেণিতে কোনো শাখা নেই। আগে শাখা যোগ করুন।');
        }
        $sheet = $_FILES['spreadsheet'] ?? null;
        $zip = $_FILES['photos'] ?? null;
        if (!is_array($sheet) || !is_array($zip)) {
            throw new RuntimeException('এক্সেল ও ছবির জিপ দুটিই দিন।');
        }
        $sheetError = (int) ($sheet['error'] ?? UPLOAD_ERR_NO_FILE);
        $zipError = (int) ($zip['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($sheetError === UPLOAD_ERR_NO_FILE || $zipError === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('এক্সেল ও ছবির জিপ দুটিই দিন।');
        }
        if (in_array($sheetError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || in_array($zipError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new RuntimeException('ফাইল সার্ভারের আপলোড সীমার চেয়ে বড়।');
        }
        if ($sheetError !== UPLOAD_ERR_OK || $zipError !== UPLOAD_ERR_OK) {
            throw new RuntimeException('ফাইল আপলোড হয়নি। আবার চেষ্টা করুন।');
        }
        $sheetName = basename(str_replace('\\', '/', (string) $sheet['name']));
        $sheetExt = strtolower(pathinfo($sheetName, PATHINFO_EXTENSION));
        if (!in_array($sheetExt, ['xlsx', 'csv'], true) || !is_uploaded_file((string) $sheet['tmp_name'])) {
            throw new RuntimeException('এক্সেল ফাইল .xlsx বা .csv হতে হবে।');
        }
        if ((int) $sheet['size'] > $sheetLimit || (int) $sheet['size'] < 1) {
            throw new RuntimeException('এক্সেল ফাইল খালি অথবা ৫ মেগাবাইটের বড়।');
        }
        if (!uploadedFileSignatureMatches((string) $sheet['tmp_name'], $sheetExt)) {
            throw new RuntimeException('এক্সেল ফাইলটি সঠিক নয়।');
        }
        $zipName = basename(str_replace('\\', '/', (string) $zip['name']));
        if (strtolower(pathinfo($zipName, PATHINFO_EXTENSION)) !== 'zip' || !is_uploaded_file((string) $zip['tmp_name'])) {
            throw new RuntimeException('ছবি একটি .zip ফাইলে দিন।');
        }
        if ((int) $zip['size'] > $zipLimit || (int) $zip['size'] < 1) {
            throw new RuntimeException('ছবির জিপ খালি অথবা অনুমোদিত আকারের চেয়ে বড়।');
        }
        $zipHead = (string) file_get_contents((string) $zip['tmp_name'], false, null, 0, 2);
        if ($zipHead !== 'PK') {
            throw new RuntimeException('ছবির জিপ ফাইলটি সঠিক নয়।');
        }

        studentImportCleanupStaging();
        $token = bin2hex(random_bytes(16));
        $directory = studentImportStagingRoot() . DIRECTORY_SEPARATOR . $token;
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('আপলোড রাখার জায়গা তৈরি করা যায়নি।');
        }
        $storedSheet = 'sheet.' . $sheetExt;
        $storedZip = $directory . DIRECTORY_SEPARATOR . 'photos.zip';
        if (!move_uploaded_file((string) $sheet['tmp_name'], $directory . DIRECTORY_SEPARATOR . $storedSheet)) {
            studentImportDeleteTree($directory);
            throw new RuntimeException('এক্সেল ফাইল রাখা যায়নি।');
        }
        if (!move_uploaded_file((string) $zip['tmp_name'], $storedZip)) {
            studentImportDeleteTree($directory);
            throw new RuntimeException('ছবির জিপ রাখা যায়নি।');
        }
        try {
            $photoList = studentImportExtractZip($storedZip, $directory . DIRECTORY_SEPARATOR . 'photos');
            unlink($storedZip);
            studentImportWriteManifest($directory, [
                'teacher_id' => $teacherId,
                'class_id' => $classId,
                'session_year' => $chosenSession,
                'spreadsheet' => $storedSheet,
                'original_name' => $sheetName,
                'created' => time(),
            ]);
            if (file_put_contents(
                $directory . DIRECTORY_SEPARATOR . 'photos.json',
                json_encode($photoList, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                LOCK_EX
            ) === false) {
                throw new RuntimeException('ছবির তালিকা রাখা যায়নি।');
            }
            $matrix = studentImportReadSpreadsheet($directory . DIRECTORY_SEPARATOR . $storedSheet, $sheetName);
            studentImportBuildPreview(
                $matrix,
                $photoList,
                schoolSections($db, $classId),
                studentImportExistingRegistrations($db),
                studentImportExistingRolls($db, $classId, $chosenSession),
                null,
                $locations
            );
        } catch (Throwable $exception) {
            studentImportDeleteTree($directory);
            throw $exception;
        }
        $_SESSION['student_import_token'] = $token;
        redirectWithMessage('student-import.php?ready=1', 'success', 'ফাইল পড়া হয়েছে। লাল সারি থাকলে ভর্তি হবে না।');
    } catch (Throwable $exception) {
        $keepPreview = $action !== 'discard' && (string) ($_SESSION['student_import_token'] ?? '') !== '';
        redirectWithMessage($keepPreview ? 'student-import.php?ready=1' : 'student-import.php', 'danger', userFacingError($exception));
    }
}

$bundle = null;
$previewError = '';
if ((string) ($_GET['ready'] ?? '') === '1') {
    try {
        $bundle = $loadPreview();
    } catch (Throwable $exception) {
        $previewError = userFacingError($exception);
    }
}
$preview = is_array($bundle) ? $bundle['preview'] : null;

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h3 class="mb-0">এক্সেল ও ছবি দিয়ে ভর্তি</h3>
              <p class="text-secondary mb-0">যেকোনো শ্রেণির তালিকা ও রেজিস্ট্রেশন নম্বরের ছবি একসাথে যোগ করুন।</p>
            </div>
            <a class="btn btn-outline-secondary" href="students.php">ছাত্র-ছাত্রী</a>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <?php if ($previewError !== ''): ?>
              <div class="alert alert-danger"><?= h($previewError) ?></div>
            <?php endif; ?>
            <?php if (!$photosPossible): ?>
              <div class="alert alert-warning">সার্ভারে Zip বা ছবি প্রস্তুত করার ব্যবস্থা নেই। হোস্টিং থেকে php-zip ও php-gd চালু করতে হবে।</div>
            <?php endif; ?>

            <?php if (is_array($preview) && is_array($bundle)): ?>
              <?php $classLabel = trim((string) $bundle['class']['class_name_ban'] . ' / ' . (string) $bundle['class']['class_name']); ?>
              <div class="card stat-card mb-3">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                      <h2 class="h5 mb-1"><?= h($classLabel) ?> · <?= h((string) $bundle['session']) ?></h2>
                      <p class="mb-0">প্রস্তুত <?= (int) $preview['ready'] ?> জন<?php if ((int) $preview['blocking'] > 0): ?> · ত্রুটি <?= (int) $preview['blocking'] ?> জন<?php endif; ?></p>
                    </div>
                    <form method="post">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="discard">
                      <input type="hidden" name="import_token" value="<?= h((string) $bundle['token']) ?>">
                      <button class="btn btn-outline-secondary" type="submit">বাতিল</button>
                    </form>
                  </div>
                  <?php if ((int) $preview['blocking'] > 0): ?>
                    <div class="alert alert-danger mt-3 mb-0">ত্রুটি থাকলে কেউ ভর্তি হবে না। ফাইল ঠিক করে আবার আপলোড করুন।</div>
                  <?php else: ?>
                    <form method="post" class="mt-3">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="commit">
                      <input type="hidden" name="import_token" value="<?= h((string) $bundle['token']) ?>">
                      <button class="btn btn-primary" type="submit">এই <?= (int) $preview['ready'] ?> জনকে ভর্তি করুন</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($preview['unused_photos'] !== []): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                      এই ছবির রেজিস্ট্রেশন নম্বর এক্সেলে নেই: <?= h(implode(', ', array_slice($preview['unused_photos'], 0, 12))) ?><?= count($preview['unused_photos']) > 12 ? '…' : '' ?>
                    </div>
                  <?php endif; ?>
                  <?php if ($preview['unrecognized_photos'] !== []): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                      এই ফাইলের নাম রেজিস্ট্রেশন নম্বর নয়: <?= h(implode(', ', array_slice($preview['unrecognized_photos'], 0, 12))) ?><?= count($preview['unrecognized_photos']) > 12 ? '…' : '' ?>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="table-responsive" style="max-height: 34rem;">
                  <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>সারি</th><th>রেজিস্ট্রেশন</th><th>নাম</th><th>শাখা</th><th>রোল</th><th>ধর্ম</th><th>ছবি</th><th>অবস্থা</th></tr></thead>
                    <tbody>
                      <?php foreach ($preview['rows'] as $row): ?>
                        <tr class="<?= $row['errors'] !== [] ? 'table-danger' : ($row['warnings'] !== [] ? 'table-warning' : '') ?>">
                          <td><?= (int) $row['line'] ?></td>
                          <td><?= h((string) $row['reg_no']) ?></td>
                          <td>
                            <?= h((string) $row['name_ban']) ?><div class="small text-secondary"><?= h((string) $row['name_eng']) ?></div>
                            <?php if (($row['fname_ban'] ?? '') !== '' || ($row['fname_eng'] ?? '') !== ''): ?><div class="small">পিতা: <?= h(trim((string) $row['fname_ban'] . ' / ' . (string) $row['fname_eng'], ' /')) ?></div><?php endif; ?>
                            <?php if (($row['mname_ban'] ?? '') !== '' || ($row['mname_eng'] ?? '') !== ''): ?><div class="small">মাতা: <?= h(trim((string) $row['mname_ban'] . ' / ' . (string) $row['mname_eng'], ' /')) ?></div><?php endif; ?>
                          </td>
                          <td><?= h((string) $row['section_label']) ?></td>
                          <td><?= h((string) $row['roll']) ?></td>
                          <td><?= h((string) $row['religion']) ?></td>
                          <td><?= h((string) ($row['photo_label'] !== '' ? $row['photo_label'] : '—')) ?></td>
                          <td class="small">
                            <?php foreach ($row['errors'] as $message): ?><div><?= h((string) $message) ?></div><?php endforeach; ?>
                            <?php foreach ($row['warnings'] as $message): ?><div class="text-secondary"><?= h((string) $message) ?></div><?php endforeach; ?>
                            <?php if ($row['errors'] === [] && $row['warnings'] === []): ?>ঠিক আছে<?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="card stat-card">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="preview">
              <div class="card-body">
                <h2 class="h5">ফাইল দিন</h2>
                <p class="text-secondary">উপবৃত্তি বা বৃত্তি এই ফাইল থেকে নেওয়া হয় না। সেটি হিসাবের পাতা থেকে ঠিক হয়। ছবি মাঝখান থেকে ১০৮×১৩৭ পিক্সেলে কেটে রাখা হয়।</p>
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label" for="importClass">শ্রেণি</label>
                    <select class="form-select" name="cls" id="importClass" required>
                      <option value="">বাছুন</option>
                      <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>"><?= h((string) $class['class_name_ban'] . ' / ' . (string) $class['class_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label" for="importSession">শিক্ষাবর্ষ</label>
                    <select class="form-select" name="session_year" id="importSession" required>
                      <?php foreach ($sessionYears as $year): ?>
                        <option value="<?= h($year) ?>" <?= $year === $sessionYear ? 'selected' : '' ?>><?= h($year) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label" for="importSheet">এক্সেল বা CSV</label>
                    <input class="form-control" type="file" name="spreadsheet" id="importSheet" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                    <div class="form-text"><a href="student-import.php?download=template">নমুনা কলাম ডাউনলোড</a></div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="importPhotos">ছবির জিপ</label>
                    <input class="form-control" type="file" name="photos" id="importPhotos" accept=".zip,application/zip" required>
                    <div class="form-text">ফাইলের নাম রেজিস্ট্রেশন নম্বর, যেমন 2511861724.jpeg। জিপের ভিতরে ফোল্ডার থাকলেও চলবে।</div>
                  </div>
                  <div class="col-12">
                    <p class="mb-2">নমুনা ফাইলে ভর্তি ফর্মের কলাম আছে: name_ban, name_eng, father_ban, father_eng, mother_ban, mother_eng, এনআইডি, জন্ম নিবন্ধন, ঠিকানা, জেলা, উপজেলা ও আগের বিদ্যালয়। খালি কলাম রাখলেও চলবে।</p>
                    <p class="mb-0 text-secondary">অবশ্যই লাগবে রেজিস্ট্রেশন, নাম, লিঙ্গ, ধর্ম, রোল ও শাখা। ধর্ম: Islam, Hindu, Buddhist, Christian অথবা ইসলাম, হিন্দু, বৌদ্ধ, খ্রিস্টান। <span id="importSectionHint">শ্রেণি বাছলে সেই শ্রেণির শাখার নাম এখানে দেখাবে।</span></p>
                  </div>
                </div>
              </div>
              <div class="card-footer">
                <button class="btn btn-primary" type="submit" <?= $photosPossible ? '' : 'disabled' ?>>মিলিয়ে দেখুন</button>
              </div>
            </form>
          </div>
        </div>
        <script>
          (function () {
            var hints = <?= json_encode($classSectionHints, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            var select = document.getElementById('importClass');
            var hint = document.getElementById('importSectionHint');
            if (!select || !hint) {
              return;
            }
            var refresh = function () {
              var id = select.value;
              var labels = hints[id] || [];
              if (!id) {
                hint.textContent = 'শ্রেণি বাছলে সেই শ্রেণির শাখার নাম এখানে দেখাবে।';
                return;
              }
              if (labels.length === 0) {
                hint.textContent = 'এই শ্রেণিতে এখনো কোনো শাখা নেই। আগে শাখা যোগ করুন।';
                return;
              }
              hint.textContent = 'এই শ্রেণির শাখা: ' + labels.join(', ') + '। এক্সেলে একই নাম লিখুন।';
            };
            select.addEventListener('change', refresh);
            refresh();
          })();
        </script>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
