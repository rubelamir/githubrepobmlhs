<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::OFFICERS_MANAGE);
$officers = accOfficers();
$basePath = '../../';
$pageTitle = 'হিসাব রক্ষক | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'appoint') {
            $teacherId = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($teacherId === false) {
                throw new RuntimeException('একজন শিক্ষক নির্বাচন করুন।');
            }
            $officers->appoint($access, accAudit(), $teacherId, (string) ($_POST['note'] ?? ''));
            redirectWithMessage('officers.php', 'success', 'হিসাব রক্ষক নিয়োগ সম্পন্ন হয়েছে।');
        }
        if ($action === 'revoke') {
            $officerId = postedRecordId('acc_officer');
            if ($officerId < 1) {
                throw new RuntimeException('নিয়োগটি শনাক্ত করা যায়নি। পাতাটি আবার লোড করে চেষ্টা করুন।');
            }
            $officers->revoke($access, accAudit(), $officerId, (string) ($_POST['reason'] ?? ''));
            redirectWithMessage('officers.php', 'success', 'হিসাব রক্ষকের দায়িত্ব প্রত্যাহার করা হয়েছে।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage('officers.php', 'danger', userFacingError($exception));
    }
}

$activeOfficers = $officers->activeOfficers();
$history = $officers->history();
$candidates = $officers->appointableTeachers();

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">হিসাব রক্ষক নিয়োগ</h3>
            <p class="text-secondary mb-0">হিসাব রক্ষক আয়-ব্যয় এন্ট্রি, ভাউচার, ব্যাংক রিকনসিলিয়েশন, শিক্ষকদের হস্তান্তর গ্রহণ ও রিপোর্ট দেখতে পারেন; ভাউচার, মওকুফ ও রিফান্ড আপনার অনুমোদনসাপেক্ষে কার্যকর হয়।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="row g-3">
              <div class="col-lg-4">
                <div class="card">
                  <div class="card-header"><h3 class="card-title mb-0">নতুন নিয়োগ</h3></div>
                  <div class="card-body">
                    <?php if ($candidates === []): ?>
                      <p class="text-secondary mb-0">নিয়োগযোগ্য কোনো সক্রিয় শিক্ষক নেই।</p>
                    <?php else: ?>
                    <form method="post" data-acc-confirm="নির্বাচিত শিক্ষককে হিসাব রক্ষক হিসেবে নিয়োগ দিতে চান?" data-acc-confirm-ok="নিয়োগ দিন">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="appoint">
                      <div class="mb-3">
                        <label class="form-label" for="acc-teacher">শিক্ষক</label>
                        <select class="form-select" id="acc-teacher" name="teacher_id" required>
                          <option value="">শিক্ষক নির্বাচন করুন</option>
                          <?php foreach ($candidates as $candidate): ?>
                            <option value="<?= (int) $candidate['id'] ?>"><?= h((string) $candidate['teacher_name']) ?><?= !empty($candidate['teacher_mobile']) ? ' — ' . h(bnDigits((string) $candidate['teacher_mobile'])) : '' ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="acc-note">মন্তব্য / আদেশ নম্বর <span class="text-secondary small">(ঐচ্ছিক)</span></label>
                        <input type="text" class="form-control" id="acc-note" name="note" maxlength="255" placeholder="যেমন: সভার সিদ্ধান্ত নং ০৫/২০২৬">
                      </div>
                      <button type="submit" class="btn btn-primary w-100"><i class="bi bi-person-plus me-1"></i>নিয়োগ দিন</button>
                    </form>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="alert alert-info mt-3 small mb-0">
                  <i class="bi bi-info-circle me-1"></i>প্রধান শিক্ষকের সব অনুমতি আগে থেকেই আছে। শ্রেণী শিক্ষকরা "Class teachers" পাতার নিয়োগ অনুযায়ী শুধু নিজ শ্রেণীর ফি আদায় করতে পারবেন।
                </div>
              </div>
              <div class="col-lg-8">
                <div class="card mb-3">
                  <div class="card-header"><h3 class="card-title mb-0">বর্তমান হিসাব রক্ষক</h3></div>
                  <div class="card-body p-0">
                    <?php if ($activeOfficers === []): ?>
                      <p class="text-secondary p-3 mb-0">এখনো কাউকে নিয়োগ দেওয়া হয়নি।</p>
                    <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0 acc-table-stack">
                        <thead><tr><th>নাম</th><th>মোবাইল</th><th>নিয়োগের সময়</th><th>নিয়োগকারী</th><th class="text-end">কার্যক্রম</th></tr></thead>
                        <tbody>
                          <?php foreach ($activeOfficers as $row): ?>
                            <?php $collapseId = 'acc-revoke-' . (int) $row['id']; ?>
                            <tr>
                              <td data-label="নাম">
                                <strong><?= h((string) ($row['teacher_name'] ?? '')) ?></strong>
                                <?php if ((string) $row['appointment_note'] !== ''): ?><div class="small text-secondary"><?= h((string) $row['appointment_note']) ?></div><?php endif; ?>
                              </td>
                              <td data-label="মোবাইল"><?= h(bnDigits((string) ($row['teacher_mobile'] ?? ''))) ?></td>
                              <td data-label="নিয়োগের সময়"><?= h(accFormatDateTime((string) $row['appointed_at'])) ?></td>
                              <td data-label="নিয়োগকারী"><?= h((string) ($row['appointed_by_name'] ?? '—')) ?></td>
                              <td class="text-end" data-label="কার্যক্রম">
                                <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($collapseId) ?>" aria-expanded="false" aria-controls="<?= h($collapseId) ?>">প্রত্যাহার</button>
                              </td>
                            </tr>
                            <tr class="collapse" id="<?= h($collapseId) ?>">
                              <td colspan="5">
                                <form method="post" class="row g-2 align-items-end" data-acc-confirm="<?= h((string) ($row['teacher_name'] ?? '')) ?>-এর হিসাব রক্ষকের দায়িত্ব প্রত্যাহার করবেন?" data-acc-confirm-variant="danger" data-acc-confirm-ok="প্রত্যাহার করুন">
                                  <?= csrfField() ?>
                                  <input type="hidden" name="action" value="revoke">
                                  <?= signedRecordField((int) $row['id'], 'acc_officer') ?>
                                  <div class="col-sm">
                                    <label class="form-label small mb-1" for="<?= h($collapseId) ?>-reason">প্রত্যাহারের কারণ</label>
                                    <input type="text" class="form-control form-control-sm" id="<?= h($collapseId) ?>-reason" name="reason" minlength="3" maxlength="255" required>
                                  </div>
                                  <div class="col-sm-auto"><button type="submit" class="btn btn-sm btn-danger w-100">নিশ্চিত করুন</button></div>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="card">
                  <div class="card-header"><h3 class="card-title mb-0">নিয়োগের ইতিহাস</h3></div>
                  <div class="card-body p-0">
                    <?php if ($history === []): ?>
                      <p class="text-secondary p-3 mb-0">কোনো ইতিহাস নেই।</p>
                    <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0 acc-table-stack">
                        <thead><tr><th>নাম</th><th>অবস্থা</th><th>নিয়োগ</th><th>প্রত্যাহার</th></tr></thead>
                        <tbody>
                          <?php foreach ($history as $row): ?>
                            <?php $isActive = $row['active_teacher_id'] !== null; ?>
                            <tr>
                              <td data-label="নাম"><?= h((string) ($row['teacher_name'] ?? '')) ?></td>
                              <td data-label="অবস্থা"><span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $isActive ? 'সক্রিয়' : 'প্রত্যাহৃত' ?></span></td>
                              <td data-label="নিয়োগ">
                                <?= h(accFormatDateTime((string) $row['appointed_at'])) ?>
                                <div class="small text-secondary"><?= h((string) ($row['appointed_by_name'] ?? '')) ?></div>
                              </td>
                              <td data-label="প্রত্যাহার">
                                <?php if ($isActive): ?>—<?php else: ?>
                                  <?= h(accFormatDateTime((string) $row['revoked_at'])) ?>
                                  <div class="small text-secondary"><?= h((string) ($row['revoked_by_name'] ?? '')) ?>: <?= h((string) ($row['revoke_reason'] ?? '')) ?></div>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
