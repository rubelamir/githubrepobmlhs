<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\StudentDirectory;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::CONCESSION_REQUEST);
$catalog = accCatalog();
$service = accStudentCategories();
$basePath = '../../';
$pageTitle = 'বৃত্তি/উপবৃত্তি শ্রেণী | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$sessionYear = (string) ($input['session_year'] ?? AuditTrail::now()->format('Y'));
if (!in_array($sessionYear, accSessionYears(), true)) {
    $sessionYear = AuditTrail::now()->format('Y');
}
$selection = accClassSelection($access, (int) ($input['cls'] ?? 0), (int) ($input['sections'] ?? 0));
$classId = $selection['class_id'];
$sectionId = $selection['section_id'];
$ready = $classId > 0 && ($selection['sections'] === [] || $sectionId > 0) && $selection['notice'] === '';
$query = ['session_year' => $sessionYear, 'cls' => $classId, 'sections' => $sectionId];
$autoApprove = $access->can(Capability::CONCESSION_APPROVE);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = 'student-categories.php?' . http_build_query($query);
    try {
        $ids = idsFromSignedRecordRefs(is_array($_POST['students'] ?? null) ? $_POST['students'] : [], 'acc_student');
        $result = $service->request(
            $access,
            accAudit(),
            $ids,
            (int) ($_POST['category_id'] ?? 0),
            (string) ($_POST['effective_from'] ?? ''),
            (string) ($_POST['reference_no'] ?? ''),
            (string) ($_POST['note'] ?? '')
        );
        $message = bnDigits((string) $result['created']) . ' জন শিক্ষার্থীর '
            . ($autoApprove ? 'ফি-শ্রেণী নির্ধারিত হয়েছে।' : 'আবেদন প্রধান শিক্ষকের অনুমোদনের জন্য পাঠানো হয়েছে।');
        if ($result['skipped'] !== []) {
            $message .= ' বাদ পড়েছে: ' . implode(', ', array_slice($result['skipped'], 0, 15))
                . (count($result['skipped']) > 15 ? ' …' : '');
        }
        redirectWithMessage($back, $result['created'] > 0 ? 'success' : 'warning', $message);
    } catch (Throwable $exception) {
        redirectWithMessage($back, 'danger', userFacingError($exception));
    }
}

$categories = $catalog->categories(true);
$students = [];
$current = [];
$pending = [];
$counts = [];
if ($ready) {
    $students = accStudents()->forClass($sessionYear, $classId, $sectionId);
    $ids = array_map(static fn (array $student): int => (int) $student['id'], $students);
    $today = accToday()->format('Y-m-d');
    $current = $service->currentMap($ids, $sessionYear, $today);
    $pending = $service->pendingMap($ids, $sessionYear);
    foreach ($students as $student) {
        $name = (string) ($current[(int) $student['id']]['category_name'] ?? 'সাধারণ');
        $counts[$name] = ($counts[$name] ?? 0) + 1;
    }
}
$today = accToday();
$defaultEffective = $today->format('Y') === $sessionYear ? $today->format('Y-m-d') : $sessionYear . '-01-01';

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">বৃত্তি/উপবৃত্তি শ্রেণী</h3>
            <p class="text-secondary mb-0">শিক্ষার্থীদের ফি-শ্রেণী (সাধারণ, উপবৃত্তিপ্রাপ্ত, বৃত্তিপ্রাপ্ত …) নির্ধারণ করুন। ফি কাঠামোতে প্রতিটি শ্রেণীর জন্য আলাদা হার থাকে; পরবর্তী ইনভয়েস থেকে সেই হার প্রযোজ্য হবে।<?= $autoApprove ? '' : ' আপনার আবেদন প্রধান শিক্ষক অনুমোদন করলে কার্যকর হবে।' ?></p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="card mb-3">
              <form method="get" class="card-body" data-student-filter>
                <div class="row g-2 align-items-end">
                  <?= accClassFilterFields($selection, $sessionYear, 'col-sm-6 col-lg-3') ?>
                  <div class="col-sm-6 col-lg-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>দেখুন</button></div>
                </div>
                <?php if ($selection['notice'] !== ''): ?><div class="alert alert-info py-2 mt-2 mb-0 small"><?= h($selection['notice']) ?></div><?php endif; ?>
              </form>
            </div>

            <?php if ($ready): ?>
              <form method="post" data-acc-confirm="নির্বাচিত শিক্ষার্থীদের ফি-শ্রেণী <?= $autoApprove ? 'নির্ধারণ' : 'পরিবর্তনের আবেদন' ?> করবেন?">
                <?= csrfField() ?>
                <?php foreach ($query as $key => $value): ?><input type="hidden" name="<?= h($key) ?>" value="<?= h((string) $value) ?>"><?php endforeach; ?>
                <div class="card mb-3">
                  <div class="card-body">
                    <div class="row g-2 align-items-end">
                      <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="cat-id">নতুন ফি-শ্রেণী</label>
                        <select class="form-select" name="category_id" id="cat-id" required>
                          <option value="">নির্বাচন করুন</option>
                          <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>"><?= h((string) $category['name_bn']) ?><?= (int) $category['requires_reference'] === 1 ? ' (আদেশ নম্বর প্রয়োজন)' : '' ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-sm-6 col-lg-2">
                        <label class="form-label" for="cat-from">কার্যকর তারিখ</label>
                        <input class="form-control" type="date" name="effective_from" id="cat-from" required value="<?= h($defaultEffective) ?>" min="<?= h($sessionYear) ?>-01-01" max="<?= h($sessionYear) ?>-12-31">
                      </div>
                      <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="cat-ref">আদেশ/রেফারেন্স নম্বর</label>
                        <input class="form-control" name="reference_no" id="cat-ref" maxlength="100" placeholder="যেমন: উপবৃত্তি তালিকা স্মারক নং">
                      </div>
                      <div class="col-sm-6 col-lg-2">
                        <label class="form-label" for="cat-note">মন্তব্য</label>
                        <input class="form-control" name="note" id="cat-note" maxlength="255">
                      </div>
                      <div class="col-lg-2">
                        <button class="btn btn-success w-100" type="submit"><i class="bi bi-check2-circle me-1"></i><?= $autoApprove ? 'নির্ধারণ করুন' : 'আবেদন পাঠান' ?></button>
                      </div>
                    </div>
                    <div class="small text-secondary mt-2">সাধারণ শ্রেণীতে ফেরাতে "সাধারণ" নির্বাচন করুন। কার্যকর তারিখের আগের ইনভয়েস অপরিবর্তিত থাকবে।</div>
                  </div>
                </div>

                <div class="card">
                  <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                    <h3 class="card-title mb-0 me-auto">শিক্ষার্থী (<?= h(bnDigits((string) count($students))) ?>)</h3>
                    <?php foreach ($counts as $name => $count): ?>
                      <span class="badge text-bg-light border"><?= h($name) ?>: <?= h(bnDigits((string) $count)) ?></span>
                    <?php endforeach; ?>
                  </div>
                  <div class="card-body p-0">
                    <?php if ($students === []): ?>
                      <p class="text-secondary p-3 mb-0">এই শ্রেণী/শাখায় কোনো ভর্তি শিক্ষার্থী নেই।</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 acc-table-stack">
                          <thead>
                            <tr>
                              <th style="width:2.5rem"><input class="form-check-input" type="checkbox" data-acc-check-all="students[]" aria-label="সবাইকে নির্বাচন"></th>
                              <th>রোল</th><th>নাম</th><th>বর্তমান ফি-শ্রেণী</th><th>অপেক্ষমাণ আবেদন</th><th class="text-end">বিস্তারিত</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($students as $student): ?>
                              <?php
                              $studentId = (int) $student['id'];
                              $assignment = $current[$studentId] ?? null;
                              $waiting = $pending[$studentId] ?? null;
                              ?>
                              <tr>
                                <td data-label="নির্বাচন"><input class="form-check-input" type="checkbox" name="students[]" value="<?= h(signedRecordRef($studentId, 'acc_student')) ?>"<?= $waiting ? ' disabled' : '' ?> aria-label="<?= h(StudentDirectory::displayName($student)) ?>"></td>
                                <td data-label="রোল"><?= h(bnDigits((string) $student['cls_roll'])) ?></td>
                                <td data-label="নাম"><?= h(StudentDirectory::displayName($student)) ?></td>
                                <td data-label="বর্তমান ফি-শ্রেণী">
                                  <?php if ($assignment): ?>
                                    <span class="badge text-bg-info"><?= h((string) $assignment['category_name']) ?></span>
                                    <div class="small text-secondary"><?= h(bnDigits((string) $assignment['effective_from'])) ?> থেকে<?= (string) $assignment['reference_no'] !== '' ? ' · ' . h((string) $assignment['reference_no']) : '' ?></div>
                                  <?php else: ?>
                                    <span class="badge text-bg-light border">সাধারণ</span>
                                  <?php endif; ?>
                                </td>
                                <td data-label="অপেক্ষমাণ আবেদন">
                                  <?= $waiting ? '<span class="badge text-bg-warning">' . h((string) $waiting['category_name']) . ' — ' . h(bnDigits((string) $waiting['effective_from'])) . '</span>' : '<span class="text-secondary">—</span>' ?>
                                </td>
                                <td class="text-end" data-label="বিস্তারিত"><a class="btn btn-sm btn-outline-secondary" href="<?= h(signedRecordUrl('student-fees.php', $studentId, 'acc_student')) ?>">ফি বিবরণী</a></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
