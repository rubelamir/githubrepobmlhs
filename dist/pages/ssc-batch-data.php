<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
ensureSscCandidateSchema($db);

$basePath = '../';
$pageTitle = 'SSC Batch Data | School Admin';
$batchYear = trim((string) ($_GET['batch'] ?? $_POST['batch'] ?? ''));
$batchYears = sscCandidateBatchYears($db);
$typeOptions = sscStudentTypeOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_ssc') {
    try {
        requireValidCsrf();
        $batchYear = trim((string) ($_POST['batch'] ?? ''));
        if ($batchYear === '') {
            throw new RuntimeException('এসএসসি ব্যাচ নির্বাচন করুন।');
        }
        if (!columnExists($db, 'students_info', 'ssc_session')) {
            throw new RuntimeException('SSC Session ফিল্ড এখনো তৈরি হয়নি। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।');
        }

        $rows = $_POST['ssc'] ?? [];
        if (!is_array($rows) || $rows === []) {
            throw new RuntimeException('কোনো শিক্ষার্থী নির্বাচিত হয়নি।');
        }

        $hasType = columnExists($db, 'students_info', 'ssc_student_type');
        $hasGroupEn = columnExists($db, 'students_info', 'ssc_group_en');
        $sql = 'UPDATE students_info
                SET ssc_session = :ssc_session,
                    ssc_reg_no = :reg,
                    ssc_roll_no = :roll,
                    ssc_group = :grp,
                    ssc_result = :result'
            . ($hasType ? ', ssc_student_type = :stype' : '')
            . ($hasGroupEn ? ', ssc_group_en = :grp_en' : '')
            . ' WHERE id = :id AND status = :status AND ssc_batch_year = :batch';
        $update = $db->prepare($sql);

        $count = 0;
        $db->beginTransaction();
        try {
            foreach ($rows as $studentRef => $fields) {
                if (!is_array($fields)) {
                    continue;
                }
                $studentId = idFromSignedRecordRef((string) $studentRef, 'student');
                if ($studentId < 1) {
                    continue;
                }
                $params = [
                    'ssc_session' => trim((string) ($fields['session'] ?? '')),
                    'reg' => trim((string) ($fields['reg'] ?? '')),
                    'roll' => trim((string) ($fields['roll'] ?? '')),
                    'grp' => trim((string) ($fields['group'] ?? '')),
                    'result' => trim((string) ($fields['result'] ?? '')),
                    'id' => $studentId,
                    'status' => studentStatusSscCandidate(),
                    'batch' => $batchYear,
                ];
                if ($hasType) {
                    $params['stype'] = normalizeSscStudentType((string) ($fields['type'] ?? ''));
                }
                if ($hasGroupEn) {
                    $groupEn = trim((string) ($fields['group_en'] ?? ''));
                    $params['grp_en'] = $groupEn !== '' ? $groupEn : sscGroupEnglish($params['grp']);
                }
                $update->execute($params);
                if ($update->rowCount() > 0) {
                    $count++;
                }
            }
            $db->commit();
        } catch (Throwable $inner) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $inner;
        }

        redirectWithMessage(
            'ssc-batch-data.php?batch=' . rawurlencode($batchYear),
            'success',
            $count . ' জনের SSC তথ্য সংরক্ষিত হয়েছে।'
        );
    } catch (Throwable $exception) {
        redirectWithMessage(
            'ssc-batch-data.php?batch=' . rawurlencode($batchYear),
            'danger',
            userFacingError($exception)
        );
    }
}

$students = [];
if ($batchYear !== '') {
    $students = sscCandidateStudents($db, $batchYear);
}

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
              <h3 class="mb-0">SSC তথ্য আপডেট</h3>
              <p class="text-secondary mb-0">
                এসএসসি ব্যাচ (Promotion থেকে) দিয়ে তালিকা লোড করুন। এখানে শুধু
                <strong>SSC Session</strong>, Type, Roll, Reg, Result যোগ/সম্পাদনা হবে—ব্যাচ বদলাবে না।
              </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-outline-secondary" href="<?= h($batchYear !== '' ? ('testimonials.php?batch=' . rawurlencode($batchYear) . '&load=1') : 'testimonials.php') ?>">
                <i class="bi bi-arrow-left me-1"></i>Testimonial পেজে ফিরুন
              </a>
              <?php if ($batchYear !== ''): ?>
                <a class="btn btn-outline-primary" href="<?= h('testimonials.php?batch=' . rawurlencode($batchYear) . '&load=1') ?>">
                  Testimonial দেখুন
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>

            <form method="get" class="card stat-card mb-3">
              <div class="card-body">
                <div class="row g-2 align-items-end">
                  <div class="col-md-4">
                    <label class="form-label" for="batchSelect">এসএসসি ব্যাচ (সাল)</label>
                    <?php if ($batchYears !== []): ?>
                      <select class="form-select" name="batch" id="batchSelect" required>
                        <option value="">ব্যাচ বাছাই করুন</option>
                        <?php foreach ($batchYears as $year): ?>
                          <option value="<?= h($year) ?>" <?= $batchYear === $year ? 'selected' : '' ?>><?= h(bnDigits($year)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <input class="form-control" name="batch" id="batchSelect" value="<?= h($batchYear) ?>" placeholder="যেমন: ২০২৬" required>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label d-none d-md-block">&nbsp;</label>
                    <button class="btn btn-outline-primary w-100" type="submit">ব্যাচ লোড করুন</button>
                  </div>
                </div>
                <div class="form-text mt-2">Class Promotion → পরীক্ষার্থী ব্যাচ (সাল) অনুযায়ী।</div>
              </div>
            </form>

            <?php if ($batchYear === ''): ?>
              <div class="card stat-card">
                <div class="card-body text-secondary">এসএসসি ব্যাচ নির্বাচন করে লোড করুন।</div>
              </div>
            <?php elseif ($batchYears === [] && $students === []): ?>
              <div class="card stat-card">
                <div class="card-body text-secondary">
                  এখনো কোনো পরীক্ষার্থী ব্যাচ নেই। Class Promotion থেকে শিক্ষার্থীদের
                  <strong>পরীক্ষার্থী ব্যাচ (SSC)</strong>-এ পাঠান।
                </div>
              </div>
            <?php elseif ($students === []): ?>
              <div class="card stat-card"><div class="card-body text-secondary">এই ব্যাচে কোনো শিক্ষার্থী নেই।</div></div>
            <?php else: ?>
              <form method="post" class="card stat-card">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_ssc">
                <input type="hidden" name="batch" value="<?= h($batchYear) ?>">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <div>
                    এসএসসি ব্যাচ <strong><?= h(bnDigits($batchYear)) ?></strong> ·
                    <strong><?= count($students) ?></strong> জন
                  </div>
                  <button class="btn btn-success btn-sm" type="submit">সংরক্ষণ করুন</button>
                </div>
                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Student</th>
                        <th>Batch</th>
                        <th>Session Year</th>
                        <th>Student Type</th>
                        <th>SSC ROLL</th>
                        <th>SSC REG NO</th>
                        <th>Group (Bangla)</th>
                        <th>Group (English)</th>
                        <th>Result</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($students as $student): ?>
                        <?php
                          $ref = signedRecordRef((int) $student['id'], 'student');
                          $currentType = normalizeSscStudentType((string) ($student['ssc_student_type'] ?? ''));
                          $batchValue = trim((string) ($student['ssc_batch_year'] ?? '')) ?: $batchYear;
                          $sessionValue = trim((string) ($student['ssc_session'] ?? ''));
                          $nameEng = trim((string) ($student['name_eng'] ?? ''));
                        ?>
                        <tr>
                          <td>
                            <div class="fw-semibold"><?= h($nameEng !== '' ? $nameEng : '—') ?></div>
                          </td>
                          <td>
                            <span class="badge text-bg-secondary"><?= h(bnDigits($batchValue)) ?></span>
                          </td>
                          <td style="min-width: 110px;">
                            <input class="form-control form-control-sm" name="ssc[<?= h($ref) ?>][session]" value="<?= h($sessionValue) ?>" placeholder="যেমন: ২০২৫-২৬">
                          </td>
                          <td style="min-width: 140px;">
                            <select class="form-select form-select-sm" name="ssc[<?= h($ref) ?>][type]">
                              <option value="">—</option>
                              <?php foreach ($typeOptions as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= $currentType === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td style="min-width: 110px;">
                            <input class="form-control form-control-sm" name="ssc[<?= h($ref) ?>][roll]" value="<?= h((string) ($student['ssc_roll_no'] ?? '')) ?>">
                          </td>
                          <td style="min-width: 120px;">
                            <input class="form-control form-control-sm" name="ssc[<?= h($ref) ?>][reg]" value="<?= h((string) ($student['ssc_reg_no'] ?? '')) ?>">
                          </td>
                          <td style="min-width: 110px;">
                            <input class="form-control form-control-sm" name="ssc[<?= h($ref) ?>][group]" value="<?= h((string) ($student['ssc_group'] ?? '')) ?>" placeholder="বিজ্ঞান/মানবিক">
                          </td>
                          <td style="min-width: 130px;">
                            <?php $groupEnValue = trim((string) ($student['ssc_group_en'] ?? '')) ?: sscGroupEnglish((string) ($student['ssc_group'] ?? '')); ?>
                            <input class="form-control form-control-sm" name="ssc[<?= h($ref) ?>][group_en]" value="<?= h($groupEnValue) ?>" placeholder="Science/Humanities">
                          </td>
                          <td style="min-width: 110px;">
                            <input class="form-control form-control-sm" name="ssc[<?= h($ref) ?>][result]" value="<?= h((string) ($student['ssc_result'] ?? '')) ?>" placeholder="GPA / ফলাফল">
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <div class="form-text mb-0">এসএসসি ব্যাচ পরিবর্তন হয় না। শুধু SSC Session ও অন্যান্য পরীক্ষার তথ্য সংরক্ষিত হয়।</div>
                  <button class="btn btn-success" type="submit">সংরক্ষণ করুন</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
