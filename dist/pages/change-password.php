<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$basePath = '../';
$pageTitle = 'Change Password | School Admin';

// Deliberately ignores any query string: a teacher may only change their own
// password, unlike profile.php which can be pointed at another teacher.
$teacher = getAuthenticatedTeacher();
if ($teacher === null) {
    header('Location: ../examples/login-v2.php', true, 302);
    exit;
}

$teacherId = (int) $teacher['id'];
$rateScope = 'password_change';
$rateKey = (string) $teacherId;
$maxLength = 72; // bcrypt silently ignores anything beyond 72 bytes.

$errors = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (isLoginRateLimited($rateScope, $rateKey)) {
        $minutes = bnDigits((string) loginRateLimitMinutesRemaining($rateScope, $rateKey));
        $formError = 'অনেকবার ভুল পাসওয়ার্ড দেওয়া হয়েছে। ' . $minutes . ' মিনিট পর আবার চেষ্টা করুন।';
    } else {
        if ($current === '') {
            $errors['current_password'] = 'বর্তমান পাসওয়ার্ড দিতে হবে।';
        }

        if ($new === '') {
            $errors['new_password'] = 'নতুন পাসওয়ার্ড দিতে হবে।';
        } elseif (strlen($new) > $maxLength) {
            $errors['new_password'] = 'নতুন পাসওয়ার্ড ' . bnDigits((string) $maxLength) . ' অক্ষরের বেশি হতে পারবে না।';
        } elseif ($new === $current) {
            $errors['new_password'] = 'নতুন পাসওয়ার্ড বর্তমান পাসওয়ার্ড থেকে ভিন্ন হতে হবে।';
        }

        if ($confirm === '') {
            $errors['confirm_password'] = 'নতুন পাসওয়ার্ডটি আবার লিখে নিশ্চিত করুন।';
        } elseif ($new !== $confirm) {
            $errors['confirm_password'] = 'দুটি পাসওয়ার্ড মিলছে না।';
        }

        // Check the current password last so a wrong one is only recorded as a
        // failed attempt when the rest of the form is otherwise valid.
        if ($errors === [] && !verifyStoredPassword($current, (string) ($teacher['teacher_password'] ?? ''))) {
            recordLoginFailure($rateScope, $rateKey);
            $errors['current_password'] = 'বর্তমান পাসওয়ার্ড সঠিক নয়।';
        }

        if ($errors === []) {
            try {
                $statement = getDatabaseConnection()->prepare(
                    'UPDATE teacher_info SET teacher_password = :password WHERE id = :id LIMIT 1'
                );
                $statement->execute([
                    'password' => hashPassword($new),
                    'id' => $teacherId,
                ]);

                clearLoginFailures($rateScope, $rateKey);
                clearLoginFailures('teacher', ltrim((string) $teacher['teacher_mobile'], '0'));
                session_regenerate_id(true);
                redirectWithMessage(
                    'change-password.php',
                    'success',
                    'পাসওয়ার্ড সফলভাবে পরিবর্তন হয়েছে। পরবর্তী লগইনে নতুন পাসওয়ার্ডটি ব্যবহার করুন।'
                );
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                $formError = 'পাসওয়ার্ড সংরক্ষণ করা যায়নি। কিছুক্ষণ পর আবার চেষ্টা করুন।';
            }
        }
    }
}

$storedPassword = (string) ($teacher['teacher_password'] ?? '');
$passwordIsPlaintext = isLegacyPlaintextPassword($storedPassword);

$pageScripts = [<<<'JS'
(() => {
  'use strict';
  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const field = document.getElementById(button.dataset.passwordToggle);
    if (!field) return;
    button.addEventListener('click', () => {
      const reveal = field.type === 'password';
      field.type = reveal ? 'text' : 'password';
      button.setAttribute('aria-pressed', String(reveal));
      button.setAttribute('aria-label', reveal ? 'পাসওয়ার্ড লুকান' : 'পাসওয়ার্ড দেখান');
      button.firstElementChild.className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  });

  const newField = document.getElementById('newPassword');
  const confirmField = document.getElementById('confirmPassword');
  const meter = document.getElementById('strengthMeter');
  const meterLabel = document.getElementById('strengthLabel');
  const matchNote = document.getElementById('matchNote');

  const levels = [
    { width: 20, className: 'bg-danger', label: 'খুব দুর্বল' },
    { width: 40, className: 'bg-danger', label: 'দুর্বল' },
    { width: 60, className: 'bg-warning', label: 'মধ্যম' },
    { width: 80, className: 'bg-info', label: 'ভালো' },
    { width: 100, className: 'bg-success', label: 'শক্তিশালী' },
  ];

  const score = (value) => {
    let points = 0;
    if (value.length >= 8) points += 1;
    if (value.length >= 12) points += 1;
    if (/[A-Za-z]/.test(value) && /\d/.test(value)) points += 1;
    if (/[^A-Za-z0-9]/.test(value)) points += 1;
    return Math.min(points, levels.length - 1);
  };

  const renderStrength = () => {
    if (!newField || !meter || !meterLabel) return;
    const value = newField.value;
    if (value === '') {
      meter.style.width = '0%';
      meter.className = 'progress-bar';
      meterLabel.textContent = '';
      return;
    }
    const level = levels[score(value)];
    meter.style.width = level.width + '%';
    meter.className = 'progress-bar ' + level.className;
    meterLabel.textContent = 'শক্তি: ' + level.label;
  };

  const renderMatch = () => {
    if (!newField || !confirmField || !matchNote) return;
    if (confirmField.value === '') {
      matchNote.textContent = '';
      matchNote.className = 'form-text';
      return;
    }
    const same = newField.value === confirmField.value;
    matchNote.textContent = same ? 'দুটি পাসওয়ার্ড মিলেছে।' : 'দুটি পাসওয়ার্ড মিলছে না।';
    matchNote.className = same ? 'form-text text-success' : 'form-text text-danger';
  };

  newField?.addEventListener('input', () => {
    renderStrength();
    renderMatch();
  });
  confirmField?.addEventListener('input', renderMatch);
})();
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">পাসওয়ার্ড পরিবর্তন</h3>
            <p class="text-secondary mb-0">নিজের অ্যাকাউন্টের পাসওয়ার্ড এখান থেকে বদলে নিতে পারবেন।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="row justify-content-center">
              <div class="col-lg-7 col-xl-6">
                <?php if ($passwordIsPlaintext): ?>
                  <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
                    <i class="bi bi-shield-exclamation mt-1" aria-hidden="true"></i>
                    <span>আপনার পাসওয়ার্ডটি এখনো পুরোনো দুর্বল পদ্ধতিতে সংরক্ষিত। এখনই একটি নতুন পাসওয়ার্ড দিলে তা নিরাপদভাবে এনক্রিপ্ট করে রাখা হবে।</span>
                  </div>
                <?php endif; ?>

                <?php if ($formError !== ''): ?>
                  <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
                    <span><?= h($formError) ?></span>
                  </div>
                <?php endif; ?>

                <div class="card stat-card">
                  <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-key" aria-hidden="true"></i>
                    <h3 class="card-title mb-0"><?= h((string) $teacher['teacher_name']) ?></h3>
                    <span class="ms-auto text-secondary small"><?= h(bnDigits((string) $teacher['teacher_mobile'])) ?></span>
                  </div>
                  <form method="post" autocomplete="off" novalidate>
                    <div class="card-body">
                    <?= csrfField() ?>

                    <div class="mb-3">
                      <label class="form-label fw-semibold" for="currentPassword">বর্তমান পাসওয়ার্ড</label>
                      <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                        <input
                          class="form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>"
                          type="password"
                          id="currentPassword"
                          name="current_password"
                          autocomplete="current-password"
                          required
                          autofocus
                        >
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="currentPassword" aria-pressed="false" aria-label="পাসওয়ার্ড দেখান">
                          <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                      </div>
                      <?php if (isset($errors['current_password'])): ?>
                        <div class="form-text text-danger"><?= h($errors['current_password']) ?></div>
                      <?php endif; ?>
                    </div>

                    <div class="mb-3">
                      <label class="form-label fw-semibold" for="newPassword">নতুন পাসওয়ার্ড</label>
                      <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                        <input
                          class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>"
                          type="password"
                          id="newPassword"
                          name="new_password"
                          autocomplete="new-password"
                          maxlength="<?= $maxLength ?>"
                          required
                        >
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="newPassword" aria-pressed="false" aria-label="পাসওয়ার্ড দেখান">
                          <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                      </div>
                      <div class="progress mt-2" style="height:6px" role="presentation">
                        <div class="progress-bar" id="strengthMeter" style="width:0%"></div>
                      </div>
                      <div class="form-text" id="strengthLabel" aria-live="polite"></div>
                      <?php if (isset($errors['new_password'])): ?>
                        <div class="form-text text-danger"><?= h($errors['new_password']) ?></div>
                      <?php endif; ?>
                    </div>

                    <div class="mb-3">
                      <label class="form-label fw-semibold" for="confirmPassword">নতুন পাসওয়ার্ড আবার লিখুন</label>
                      <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-check2-square" aria-hidden="true"></i></span>
                        <input
                          class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                          type="password"
                          id="confirmPassword"
                          name="confirm_password"
                          autocomplete="new-password"
                          required
                        >
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="confirmPassword" aria-pressed="false" aria-label="পাসওয়ার্ড দেখান">
                          <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                      </div>
                      <div class="form-text" id="matchNote" aria-live="polite"></div>
                      <?php if (isset($errors['confirm_password'])): ?>
                        <div class="form-text text-danger"><?= h($errors['confirm_password']) ?></div>
                      <?php endif; ?>
                    </div>

                    <div class="border-top pt-3">
                      <p class="text-secondary small mb-2">পাসওয়ার্ড তৈরির নিয়ম:</p>
                      <ul class="text-secondary small mb-0 ps-3">
                        <li>নতুন পাসওয়ার্ড খালি রাখা যাবে না।</li>
                        <li>বর্তমান পাসওয়ার্ড থেকে ভিন্ন হতে হবে।</li>
                        <li>নিজের মোবাইল নম্বর বা জন্মতারিখ ব্যবহার না করাই ভালো।</li>
                      </ul>
                    </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                      <a class="btn btn-outline-secondary" href="profile.php">
                        <i class="bi bi-arrow-left-short" aria-hidden="true"></i> প্রোফাইলে ফিরে যান
                      </a>
                      <button class="btn btn-primary" type="submit">
                        <i class="bi bi-save me-1" aria-hidden="true"></i>পাসওয়ার্ড সংরক্ষণ করুন
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
