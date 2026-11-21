<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/shared/student-birthdate-fix.php';

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
    redirectWithMessage('student-birthdate-fix.php', 'danger', 'ফাইলটি সার্ভারের আপলোড সীমার চেয়ে বড়।');
}

requireAuthentication('../examples/login-v2.php');
set_time_limit(120);

$db = getDatabaseConnection();
$teacher = getAuthenticatedTeacher();
if (!canManageModule('admissions', $teacher)) {
    denyPanelAccess();
}
if (function_exists('ensureStudentRegistrationColumn')) {
    ensureStudentRegistrationColumn($db);
}

$basePath = '../';
$pageTitle = 'জন্মতারিখ সংশোধন | School Admin';
$sheetLimit = 5 * 1024 * 1024;

if ((string) ($_GET['download'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="birthdate-fix-template.csv"');
    echo studentBirthdateFixTemplateCsv();
    exit;
}

$previewKey = 'student_birthdate_fix_preview';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'discard') {
            unset($_SESSION[$previewKey]);
            redirectWithMessage('student-birthdate-fix.php', 'success', 'প্রিভিউ বাতিল করা হয়েছে।');
        }
        if ($action === 'commit') {
            $stored = $_SESSION[$previewKey] ?? null;
            if (!is_array($stored) || !isset($stored['preview']['rows']) || !is_array($stored['preview']['rows'])) {
                throw new RuntimeException('প্রিভিউ আর নেই। আবার ফাইল দিন।');
            }
            $preview = $stored['preview'];
            if ((int) ($preview['blocking'] ?? 0) > 0) {
                throw new RuntimeException('লাল সারি থাকলে সংশোধন হবে না।');
            }
            $updated = studentBirthdateFixApply($db, $preview['rows']);
            unset($_SESSION[$previewKey]);
            redirectWithMessage(
                'student-birthdate-fix.php',
                'success',
                $updated > 0
                    ? $updated . ' জনের জন্মতারিখ সংশোধন করা হয়েছে।'
                    : 'সব তারিখ আগে থেকেই সঠিক ছিল।'
            );
        }

        $sheet = $_FILES['spreadsheet'] ?? null;
        if (!is_array($sheet) || (int) ($sheet['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('এক্সেল বা CSV ফাইল দিন।');
        }
        if ((int) ($sheet['size'] ?? 0) > $sheetLimit) {
            throw new RuntimeException('ফাইল ৫ মেগাবাইটের বেশি হতে পারবে না।');
        }
        $sheetName = (string) ($sheet['name'] ?? 'sheet.xlsx');
        $tmp = (string) ($sheet['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('ফাইল পাওয়া যায়নি।');
        }
        $matrix = studentImportReadSpreadsheet($tmp, $sheetName);
        $preview = studentBirthdateFixBuildPreview($db, $matrix);
        $_SESSION[$previewKey] = [
            'preview' => $preview,
            'original_name' => $sheetName,
            'created' => time(),
        ];
        redirectWithMessage('student-birthdate-fix.php?ready=1', 'success', 'ফাইল পড়া হয়েছে। লাল সারি থাকলে সংশোধন হবে না।');
    } catch (Throwable $exception) {
        $keep = $action === 'commit' && isset($_SESSION[$previewKey]);
        redirectWithMessage(
            $keep ? 'student-birthdate-fix.php?ready=1' : 'student-birthdate-fix.php',
            'danger',
            userFacingError($exception)
        );
    }
}

$preview = null;
$previewError = '';
if ((string) ($_GET['ready'] ?? '') === '1') {
    $stored = $_SESSION[$previewKey] ?? null;
    if (!is_array($stored) || !isset($stored['preview']) || !is_array($stored['preview'])) {
        $previewError = 'প্রিভিউ আর নেই। আবার ফাইল দিন।';
    } else {
        $preview = $stored['preview'];
    }
}

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h3 class="mb-0">জন্মতারিখ সংশোধন</h3>
              <p class="text-secondary mb-0">রেজিস্ট্রেশন নম্বর মিলিয়ে এক্সেল থেকে সঠিক জন্মতারিখ বসান।</p>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <?php if ($previewError !== ''): ?>
              <div class="alert alert-danger"><?= h($previewError) ?></div>
            <?php endif; ?>
            <?php if (is_array($preview)): ?>
              <div class="card stat-card mb-3">
                <div class="card-body d-flex flex-wrap gap-3 justify-content-between align-items-center">
                  <div>
                    <div><strong><?= (int) $preview['ready'] ?></strong> জন সংশোধন হবে</div>
                    <div class="text-secondary small">অপরিবর্তিত <?= (int) $preview['unchanged'] ?>, সমস্যা <?= (int) $preview['blocking'] ?></div>
                  </div>
                  <div class="d-flex gap-2 flex-wrap">
                    <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="discard"><button class="btn btn-outline-secondary" type="submit">বাতিল</button></form>
                    <?php if ((int) $preview['blocking'] === 0 && (int) $preview['ready'] > 0): ?>
                      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="commit"><button class="btn btn-primary" type="submit">সংশোধন সংরক্ষণ করুন</button></form>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="table-responsive" style="max-height: 34rem;">
                  <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>সারি</th><th>রেজিস্ট্রেশন</th><th>নাম</th><th>রোল</th><th>বর্তমান</th><th>নতুন</th><th>অবস্থা</th></tr></thead>
                    <tbody>
                      <?php foreach ($preview['rows'] as $row): ?>
                        <tr class="<?= $row['errors'] !== [] ? 'table-danger' : ($row['unchanged'] ? '' : 'table-warning') ?>">
                          <td><?= (int) $row['line'] ?></td>
                          <td><?= h((string) $row['reg_no']) ?></td>
                          <td><?= h((string) $row['name']) ?></td>
                          <td><?= h((string) $row['roll']) ?></td>
                          <td><?= h((string) ($row['current_birth_date'] !== '' ? $row['current_birth_date'] : '—')) ?></td>
                          <td><?= h((string) ($row['new_birth_date'] !== '' ? $row['new_birth_date'] : '—')) ?></td>
                          <td class="small">
                            <?php foreach ($row['errors'] as $message): ?><div><?= h((string) $message) ?></div><?php endforeach; ?>
                            <?php if ($row['errors'] === [] && $row['unchanged']): ?>আগে থেকেই ঠিক<?php elseif ($row['errors'] === []): ?>সংশোধন হবে<?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>

            <div class="card stat-card">
              <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <div class="card-body">
                  <div class="row g-3">
                    <div class="col-md-8">
                      <label class="form-label" for="birthFixSheet">এক্সেল বা CSV</label>
                      <input class="form-control" type="file" name="spreadsheet" id="birthFixSheet" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                      <div class="form-text"><a href="student-birthdate-fix.php?download=template">নমুনা কলাম ডাউনলোড</a></div>
                    </div>
                    <div class="col-12">
                      <p class="mb-0 text-secondary">কলাম: <code>registration_no</code>, <code>birth_date</code>। তারিখ <code>YYYY-MM-DD</code> অথবা এক্সেলের তারিখ ফরম্যাট চলবে। শুধু মিলে যাওয়া রেজিস্ট্রেশন নম্বরের জন্মতারিখ বদলাবে।</p>
                    </div>
                  </div>
                </div>
                <div class="card-footer">
                  <button class="btn btn-primary" type="submit">মিলিয়ে দেখুন</button>
                </div>
              </form>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
