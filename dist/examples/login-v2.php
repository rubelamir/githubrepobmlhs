<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (isAuthenticated()) {
    header('Location: ../index.php', true, 302);
    exit;
}

// Remembers only the mobile number so returning staff do not retype it; the
// session itself is never persisted in a cookie.
$rememberCookie = 'admin_login_mobile';
$rememberPath = projectBaseUrl() . '/';

try {
    $school = schoolSettings(getDatabaseConnection());
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $school = [];
}

$schoolNameBn = (string) ($school['school_name_bn'] ?? 'বিদ্যালয়');
$schoolNameEn = (string) ($school['school_name_en'] ?? 'School Admin');
$schoolAddress = (string) ($school['address'] ?? '');
$schoolEiin = (string) ($school['eiin'] ?? '');
$schoolEstablished = (string) ($school['established'] ?? '');
$logoUrl = schoolFileUrl((string) ($school['logo'] ?? ''), '', '');

[$flashType, $flashMessage] = consumeFlash();
$mobile = (string) ($_COOKIE[$rememberCookie] ?? '');
$remember = $mobile !== '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mobile = trim((string) ($_POST['mobile'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    // Staff type the number with or without dashes, spaces or the +88 prefix.
    $digits = (string) preg_replace('/\D+/', '', asciiDigits($mobile));
    if (strlen($digits) === 13 && str_starts_with($digits, '88')) {
        $digits = substr($digits, 2);
    }
    // Part of teacher_info was imported without the leading zero, so both the
    // typed number and the stored one are compared without it.
    $core = ltrim($digits, '0');

    if (!verifyCsrfToken()) {
        $error = 'নিরাপত্তা টোকেনের মেয়াদ শেষ হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।';
    } elseif ($mobile === '' || $password === '') {
        $error = 'মোবাইল নম্বর ও পাসওয়ার্ড দুটোই দিতে হবে।';
    } elseif (strlen($core) < 6) {
        $error = 'সঠিক মোবাইল নম্বর লিখুন।';
    } elseif (isLoginRateLimited('teacher', $core)) {
        $minutes = bnDigits((string) loginRateLimitMinutesRemaining('teacher', $core));
        $error = 'অনেকবার ভুল চেষ্টা করা হয়েছে। ' . $minutes . ' মিনিট পর আবার চেষ্টা করুন।';
    } else {
        try {
            $db = getDatabaseConnection();
            $normalizedTeacherMobile = "REPLACE(REPLACE(REPLACE(teacher_mobile, '-', ''), ' ', ''), '+', '')";
            foreach (['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'] as $banglaDigit => $asciiDigit) {
                $normalizedTeacherMobile = "REPLACE({$normalizedTeacherMobile}, '{$banglaDigit}', '{$asciiDigit}')";
            }
            $statement = $db->prepare(
                "SELECT id, teacher_mobile, teacher_password, teacher_status
                 FROM teacher_info
                 WHERE teacher_mobile = :mobile
                    OR TRIM(LEADING '0' FROM CASE
                        WHEN LEFT({$normalizedTeacherMobile}, 3) = '880' THEN SUBSTRING({$normalizedTeacherMobile}, 4)
                        WHEN LEFT({$normalizedTeacherMobile}, 2) = '88' THEN SUBSTRING({$normalizedTeacherMobile}, 3)
                        ELSE {$normalizedTeacherMobile}
                    END) = :core
                 LIMIT 1"
            );
            $statement->execute(['mobile' => $mobile, 'core' => $core]);
            $teacher = $statement->fetch();
            $storedPassword = (string) ($teacher['teacher_password'] ?? '');
            $credentialsValid = $teacher && verifyStoredPassword($password, $storedPassword);

            if ($credentialsValid && isTeacherActiveStatus($teacher['teacher_status'] ?? 0)) {
                if (isLegacyPlaintextPassword($storedPassword)) {
                    $migrate = $db->prepare(
                        'UPDATE teacher_info SET teacher_password = :password WHERE id = :id'
                    );
                    $migrate->execute([
                        'password' => hashPassword($password),
                        'id' => (int) $teacher['id'],
                    ]);
                }

                clearLoginFailures('teacher', $core);
                setcookie($rememberCookie, $remember ? (string) $teacher['teacher_mobile'] : '', [
                    'expires' => $remember ? time() + 2592000 : time() - 3600,
                    'path' => $rememberPath,
                    'secure' => !empty($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                establishAdminTeacherSession((int) $teacher['id'], (string) $teacher['teacher_mobile']);
                header('Location: ../index.php', true, 302);
                exit;
            }

            recordLoginFailure('teacher', $core);
            // Only a holder of the correct password learns the account is disabled.
            $error = $credentialsValid
                ? 'আপনার অ্যাকাউন্টটি নিষ্ক্রিয় করা হয়েছে। প্রধান শিক্ষকের সাথে যোগাযোগ করুন।'
                : 'মোবাইল নম্বর অথবা পাসওয়ার্ড সঠিক নয়।';
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'লগইন সেবার সাথে সংযোগ করা যাচ্ছে না। কিছুক্ষণ পর আবার চেষ্টা করুন।';
        }
    }
}
?>
<!doctype html>
<html lang="bn">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <title><?= h($schoolNameBn) ?> | প্রশাসনিক লগইন</title>
    <script>
      (() => {
        'use strict';
        const root = document.documentElement;
        const STORAGE_KEY = 'lte-theme';
        let stored = null;
        try { stored = localStorage.getItem(STORAGE_KEY); } catch {}
        let resolved = 'light';
        if (stored === 'dark' || stored === 'light') resolved = stored;
        else if (globalThis.matchMedia('(prefers-color-scheme: dark)').matches) resolved = 'dark';
        root.setAttribute('data-bs-theme', resolved);
        root.style.colorScheme = resolved;
      })();
    </script>
    <meta name="robots" content="noindex, nofollow" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#1a4d3e" />
    <meta name="description" content="<?= h($schoolNameEn) ?> — administrative panel sign in." />
    <?php if ($logoUrl !== ''): ?>
    <link rel="icon" href="<?= h($logoUrl) ?>" />
    <?php endif; ?>
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
      crossorigin="anonymous"
    />
    <link rel="stylesheet" href="../css/adminlte.css" />
    <link rel="stylesheet" href="../css/responsive.css?v=<?= (int) @filemtime(__DIR__ . '/../css/responsive.css') ?>" />
    <link rel="stylesheet" href="../css/auth-pages.css?v=<?= (int) @filemtime(__DIR__ . '/../css/auth-pages.css') ?>" />
  </head>
  <body class="slogin-page">
    <main class="slogin-card">
      <aside class="slogin-brand">
        <div class="slogin-emblem">
          <?php if ($logoUrl !== ''): ?>
            <img src="<?= h($logoUrl) ?>" alt="<?= h($schoolNameEn) ?>" width="104" height="104" />
          <?php else: ?>
            <span aria-hidden="true"><?= h(mb_substr($schoolNameEn, 0, 1)) ?></span>
          <?php endif; ?>
        </div>
        <div>
          <h1 class="slogin-name"><?= h($schoolNameBn) ?></h1>
          <p class="slogin-name-en"><?= h($schoolNameEn) ?></p>
        </div>
        <span class="slogin-tag"><i class="bi bi-shield-lock" aria-hidden="true"></i> প্রশাসনিক প্যানেল</span>
        <div class="slogin-rule"></div>
        <ul class="slogin-meta">
          <?php if ($schoolAddress !== ''): ?>
            <li><i class="bi bi-geo-alt-fill" aria-hidden="true"></i><span><?= h($schoolAddress) ?></span></li>
          <?php endif; ?>
          <?php if ($schoolEiin !== ''): ?>
            <li><i class="bi bi-hash" aria-hidden="true"></i><span>ইআইআইএন: <?= h(bnDigits($schoolEiin)) ?></span></li>
          <?php endif; ?>
          <?php if ($schoolEstablished !== ''): ?>
            <li><i class="bi bi-calendar-event-fill" aria-hidden="true"></i><span>স্থাপিত: <?= h(bnDigits($schoolEstablished)) ?></span></li>
          <?php endif; ?>
        </ul>
      </aside>

      <section class="slogin-form">
        <h2 class="slogin-title">প্রশাসনিক প্যানেলে প্রবেশ</h2>
        <p class="slogin-subtitle">শিক্ষক ও কর্মচারীবৃন্দ নিজের মোবাইল নম্বর ও পাসওয়ার্ড দিয়ে প্রবেশ করুন।</p>

        <div aria-live="polite">
          <?php if ($flashType === 'success' && $flashMessage !== ''): ?>
            <div class="alert alert-success d-flex align-items-start gap-2" role="status">
              <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
              <span><?= h($flashMessage) ?></span>
            </div>
          <?php endif; ?>
          <?php if ($error !== ''): ?>
            <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
              <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
              <span><?= h($error) ?></span>
            </div>
          <?php endif; ?>
        </div>

        <form action="login-v2.php" method="post" id="loginForm" novalidate>
          <?= csrfField() ?>

          <div class="mb-3">
            <label for="loginMobile" class="form-label fw-semibold">মোবাইল নম্বর</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-phone" aria-hidden="true"></i></span>
              <input
                id="loginMobile"
                name="mobile"
                type="tel"
                class="form-control"
                value="<?= h($mobile) ?>"
                placeholder="০১XXXXXXXXX"
                inputmode="numeric"
                autocomplete="username"
                maxlength="20"
                required
                aria-describedby="mobileFeedback"
                <?= $mobile === '' ? 'autofocus' : '' ?>
              />
              <div class="invalid-feedback" id="mobileFeedback">সঠিক মোবাইল নম্বর লিখুন।</div>
            </div>
          </div>

          <div class="mb-3">
            <label for="loginPassword" class="form-label fw-semibold">পাসওয়ার্ড</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock-fill" aria-hidden="true"></i></span>
              <input
                id="loginPassword"
                name="password"
                type="password"
                class="form-control"
                autocomplete="current-password"
                aria-describedby="passwordFeedback capsWarning"
                required
                <?= $mobile === '' ? '' : 'autofocus' ?>
              />
              <button
                class="btn btn-outline-secondary"
                type="button"
                id="togglePassword"
                aria-controls="loginPassword"
                aria-pressed="false"
                aria-label="পাসওয়ার্ড দেখান"
              >
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
              <div class="invalid-feedback" id="passwordFeedback">পাসওয়ার্ড লিখুন।</div>
            </div>
            <div class="form-text text-warning-emphasis d-none" id="capsWarning" role="status">
              <i class="bi bi-capslock-fill" aria-hidden="true"></i> ক্যাপস লক চালু আছে
            </div>
          </div>

          <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="form-check mb-0">
              <input class="form-check-input" type="checkbox" name="remember" value="1" id="rememberMobile" <?= $remember ? 'checked' : '' ?> />
              <label class="form-check-label" for="rememberMobile">নম্বরটি মনে রাখুন</label>
            </div>
            <a class="link-secondary text-decoration-none small" href="forgot-password.php">পাসওয়ার্ড ভুলে গেছেন?</a>
          </div>

          <div class="d-grid">
            <button type="submit" class="btn slogin-submit" id="loginSubmit">
              <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i> প্রবেশ করুন
            </button>
          </div>
        </form>

        <div class="slogin-foot">
          <p class="mb-1">
            সমস্যা হলে প্রধান শিক্ষক অথবা অফিস সহকারীর সাথে
            <a href="<?= h(publicSiteUrl('contact.php')) ?>">যোগাযোগ করুন</a>।
          </p>
          <p class="mb-0">
            <a href="<?= h(publicSiteUrl('index.php')) ?>">
              <i class="bi bi-arrow-left-short" aria-hidden="true"></i>স্কুল ওয়েবসাইটে ফিরে যান
            </a>
          </p>
        </div>
      </section>
    </main>

    <script>
      (() => {
        'use strict';
        const field = document.getElementById('loginPassword');
        const toggle = document.getElementById('togglePassword');
        if (field && toggle) {
          toggle.addEventListener('click', () => {
            const reveal = field.type === 'password';
            field.type = reveal ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', String(reveal));
            toggle.setAttribute('aria-label', reveal ? 'পাসওয়ার্ড লুকান' : 'পাসওয়ার্ড দেখান');
            toggle.firstElementChild.className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
            field.focus();
          });
        }

        const caps = document.getElementById('capsWarning');
        const updateCaps = (event) => {
          if (!caps || typeof event.getModifierState !== 'function') return;
          caps.classList.toggle('d-none', !event.getModifierState('CapsLock'));
        };
        field?.addEventListener('keydown', updateCaps);
        field?.addEventListener('keyup', updateCaps);
        field?.addEventListener('blur', () => caps?.classList.add('d-none'));

        const form = document.getElementById('loginForm');
        const submit = document.getElementById('loginSubmit');
        const mobileInput = document.getElementById('loginMobile');
        const banglaDigits = '০১২৩৪৫৬৭৮৯';
        const digitCount = (value) =>
          Array.from(value).filter((ch) => /[0-9]/.test(ch) || banglaDigits.includes(ch)).length;
        const check = (input, valid) => {
          input.classList.toggle('is-invalid', !valid);
          input.setAttribute('aria-invalid', String(!valid));
          return valid;
        };
        // Mirrors the server rule (at least six digits) so legacy numbers are not rejected here.
        const mobileOk = () => check(mobileInput, digitCount(mobileInput.value) >= 6);
        const passwordOk = () => check(field, field.value !== '');
        mobileInput?.addEventListener('blur', () => mobileInput.value !== '' && mobileOk());
        mobileInput?.addEventListener('input', () => mobileInput.classList.contains('is-invalid') && mobileOk());
        field?.addEventListener('input', () => field.classList.contains('is-invalid') && passwordOk());

        form?.addEventListener('submit', (event) => {
          if (!submit || !mobileInput || !field) return;
          const mobileValid = mobileOk();
          const passwordValid = passwordOk();
          if (!mobileValid || !passwordValid) {
            event.preventDefault();
            (mobileValid ? field : mobileInput).focus();
            return;
          }
          submit.disabled = true;
          submit.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>প্রবেশ করা হচ্ছে…';
        });
      })();
    </script>
  </body>
</html>
