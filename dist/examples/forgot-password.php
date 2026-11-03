<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password-reset.php';

if (isAuthenticated()) {
    header('Location: ../index.php', true, 302);
    exit;
}

try {
    $db = getDatabaseConnection();
    $school = schoolSettings($db);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $db = null;
    $school = [];
}

$schoolNameBn = (string) ($school['school_name_bn'] ?? 'বিদ্যালয়');
$schoolNameEn = (string) ($school['school_name_en'] ?? 'School Admin');
$logoUrl = schoolFileUrl((string) ($school['logo'] ?? ''), '', '');

$state = $_SESSION['pw_reset'] ?? null;
if (!is_array($state) || (int) ($state['expires'] ?? 0) < time()) {
    $state = null;
    unset($_SESSION['pw_reset']);
}
$step = $state['step'] ?? 'mobile';
if ($step === 'new' && (int) ($state['verified_until'] ?? 0) < time()) {
    $state = null;
    $step = 'mobile';
    unset($_SESSION['pw_reset']);
}

$error = '';
$notice = '';
$mobile = (string) ($state['mobile'] ?? '');
$genericSentNotice = 'নম্বরটি নিবন্ধিত থাকলে একটি ৬ অঙ্কের কোড SMS-এ পাঠানো হয়েছে। কোডটি ' . bnDigits((string) (PASSWORD_RESET_CODE_TTL / 60)) . ' মিনিট কার্যকর থাকবে।';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db instanceof PDO) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!verifyCsrfToken()) {
            $error = 'নিরাপত্তা টোকেনের মেয়াদ শেষ হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।';
        } elseif ($action === 'cancel') {
            unset($_SESSION['pw_reset']);
            header('Location: forgot-password.php', true, 303);
            exit;
        } elseif ($action === 'send') {
            $mobile = trim((string) ($_POST['mobile'] ?? $mobile));
            $core = passwordResetMobileCore($mobile);
            $secondsSinceSend = time() - (int) ($state['sent_at'] ?? 0);

            if (strlen($core) < 6) {
                $error = 'সঠিক মোবাইল নম্বর লিখুন।';
            } elseif ($state !== null && $secondsSinceSend < PASSWORD_RESET_RESEND_SECONDS) {
                $error = 'নতুন কোডের জন্য অনুগ্রহ করে ' . bnDigits((string) (PASSWORD_RESET_RESEND_SECONDS - $secondsSinceSend)) . ' সেকেন্ড অপেক্ষা করুন।';
                $step = 'otp';
            } else {
                $teacher = passwordResetFindTeacher($db, $mobile);
                $result = $teacher !== null
                    ? passwordResetSendCode($db, $teacher)
                    : (smsGatewayReady($db) ? 'sent' : 'sms_unavailable');

                if ($result === 'sms_unavailable') {
                    $error = 'এই মুহূর্তে SMS সেবা চালু নেই। প্রধান শিক্ষক অথবা অফিস সহকারীর সাথে যোগাযোগ করুন।';
                } else {
                    // The reply is identical for unknown, throttled and failed sends so the form cannot reveal which numbers are registered.
                    $state = [
                        'step' => 'otp',
                        'mobile' => $mobile,
                        'teacher_id' => $teacher !== null ? (int) $teacher['id'] : 0,
                        'teacher_mobile' => $teacher !== null ? (string) $teacher['teacher_mobile'] : '',
                        'sent_at' => time(),
                        'expires' => time() + 1800,
                    ];
                    $_SESSION['pw_reset'] = $state;
                    $step = 'otp';
                    $notice = $genericSentNotice;
                }
            }
        } elseif ($action === 'verify' && $state !== null && $step === 'otp') {
            $core = passwordResetMobileCore((string) $state['mobile']);
            if (isLoginRateLimited('pw-reset-otp', $core)) {
                $minutes = bnDigits((string) loginRateLimitMinutesRemaining('pw-reset-otp', $core));
                $error = 'অনেকবার ভুল কোড দেওয়া হয়েছে। ' . $minutes . ' মিনিট পর আবার চেষ্টা করুন।';
            } else {
                $outcome = passwordResetCheckCode($db, (int) $state['teacher_id'], (string) ($_POST['code'] ?? ''));
                if ($outcome === 'ok') {
                    clearLoginFailures('pw-reset-otp', $core);
                    session_regenerate_id(true);
                    $state['step'] = 'new';
                    $state['verified_until'] = time() + PASSWORD_RESET_VERIFIED_TTL;
                    $_SESSION['pw_reset'] = $state;
                    $step = 'new';
                } else {
                    recordLoginFailure('pw-reset-otp', $core);
                    $error = 'কোডটি সঠিক নয় অথবা মেয়াদ শেষ হয়ে গেছে।';
                }
            }
        } elseif ($action === 'save' && $state !== null && $step === 'new' && (int) $state['teacher_id'] > 0) {
            $password = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            if (strlen($password) < PASSWORD_RESET_MIN_LENGTH) {
                $error = 'পাসওয়ার্ড কমপক্ষে ' . bnDigits((string) PASSWORD_RESET_MIN_LENGTH) . ' অক্ষরের হতে হবে।';
            } elseif (strlen($password) > PASSWORD_RESET_MAX_LENGTH) {
                $error = 'পাসওয়ার্ড ' . bnDigits((string) PASSWORD_RESET_MAX_LENGTH) . ' অক্ষরের বেশি হতে পারবে না।';
            } elseif ($password !== $confirm) {
                $error = 'দুটি পাসওয়ার্ড মিলছে না।';
            } else {
                passwordResetSaveNewPassword($db, (int) $state['teacher_id'], (string) $state['teacher_mobile'], $password);
                unset($_SESSION['pw_reset']);
                session_regenerate_id(true);
                redirectWithMessage('login-v2.php', 'success', 'পাসওয়ার্ড সফলভাবে পরিবর্তন হয়েছে। নতুন পাসওয়ার্ড দিয়ে প্রবেশ করুন।');
            }
        } else {
            $error = 'অনুরোধটি সম্পন্ন করা যায়নি। প্রথম থেকে আবার চেষ্টা করুন।';
        }
    } catch (PDOException | RuntimeException $exception) {
        error_log($exception->getMessage());
        $error = 'সেবাটি এই মুহূর্তে পাওয়া যাচ্ছে না। কিছুক্ষণ পর আবার চেষ্টা করুন।';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = 'সেবাটি এই মুহূর্তে পাওয়া যাচ্ছে না। কিছুক্ষণ পর আবার চেষ্টা করুন।';
}

$titles = [
    'mobile' => ['পাসওয়ার্ড পুনরুদ্ধার', 'আপনার নিবন্ধিত মোবাইল নম্বর দিন। SMS-এ একটি ৬ অঙ্কের কোড পাঠানো হবে।'],
    'otp' => ['কোড যাচাই করুন', 'মোবাইলে পাঠানো ৬ অঙ্কের কোডটি লিখুন।'],
    'new' => ['নতুন পাসওয়ার্ড দিন', 'নিজের জন্য একটি নতুন পাসওয়ার্ড নির্বাচন করুন।'],
];
[$pageTitle, $pageSubtitle] = $titles[$step];
?>
<!doctype html>
<html lang="bn">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <title><?= h($schoolNameBn) ?> | পাসওয়ার্ড পুনরুদ্ধার</title>
    <script>
      (() => {
        'use strict';
        const root = document.documentElement;
        let stored = null;
        try { stored = localStorage.getItem('lte-theme'); } catch {}
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
      </aside>

      <section class="slogin-form">
        <ol class="slogin-steps" aria-label="ধাপ">
          <li class="<?= $step === 'mobile' ? 'is-current' : 'is-done' ?>">মোবাইল</li>
          <li class="<?= $step === 'otp' ? 'is-current' : ($step === 'new' ? 'is-done' : '') ?>">কোড</li>
          <li class="<?= $step === 'new' ? 'is-current' : '' ?>">নতুন পাসওয়ার্ড</li>
        </ol>

        <h2 class="slogin-title"><?= h($pageTitle) ?></h2>
        <p class="slogin-subtitle"><?= h($pageSubtitle) ?></p>

        <div aria-live="polite">
          <?php if ($error !== ''): ?>
            <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
              <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
              <span><?= h($error) ?></span>
            </div>
          <?php endif; ?>
          <?php if ($notice !== ''): ?>
            <div class="alert alert-success d-flex align-items-start gap-2" role="status">
              <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
              <span><?= h($notice) ?></span>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($step === 'mobile'): ?>
        <form action="forgot-password.php" method="post" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="action" value="send" />
          <div class="mb-4">
            <label for="resetMobile" class="form-label fw-semibold">মোবাইল নম্বর</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-phone" aria-hidden="true"></i></span>
              <input
                id="resetMobile"
                name="mobile"
                type="tel"
                class="form-control"
                value="<?= h($mobile) ?>"
                placeholder="০১XXXXXXXXX"
                inputmode="numeric"
                autocomplete="username"
                maxlength="20"
                required
                autofocus
              />
            </div>
          </div>
          <div class="d-grid">
            <button type="submit" class="btn slogin-submit">
              <i class="bi bi-chat-dots me-1" aria-hidden="true"></i> কোড পাঠান
            </button>
          </div>
        </form>

        <?php elseif ($step === 'otp'): ?>
        <form action="forgot-password.php" method="post" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="action" value="verify" />
          <div class="mb-4">
            <label for="resetCode" class="form-label fw-semibold">যাচাইকরণ কোড</label>
            <input
              id="resetCode"
              name="code"
              type="text"
              class="form-control slogin-otp"
              inputmode="numeric"
              pattern="[0-9০-৯]{6}"
              autocomplete="one-time-code"
              maxlength="6"
              placeholder="······"
              required
              autofocus
            />
          </div>
          <div class="d-grid mb-3">
            <button type="submit" class="btn slogin-submit">
              <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> যাচাই করুন
            </button>
          </div>
        </form>
        <div class="d-flex flex-wrap justify-content-between gap-2 small">
          <form action="forgot-password.php" method="post" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send" />
            <input type="hidden" name="mobile" value="<?= h($mobile) ?>" />
            <button type="submit" class="btn btn-link btn-sm p-0">কোড পাননি? আবার পাঠান</button>
          </form>
          <form action="forgot-password.php" method="post" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel" />
            <button type="submit" class="btn btn-link btn-sm p-0 link-secondary">নম্বর পরিবর্তন করুন</button>
          </form>
        </div>

        <?php else: ?>
        <form action="forgot-password.php" method="post" id="newPasswordForm" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="action" value="save" />
          <div class="mb-3">
            <label for="newPassword" class="form-label fw-semibold">নতুন পাসওয়ার্ড</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock-fill" aria-hidden="true"></i></span>
              <input
                id="newPassword"
                name="new_password"
                type="password"
                class="form-control"
                autocomplete="new-password"
                minlength="<?= PASSWORD_RESET_MIN_LENGTH ?>"
                maxlength="<?= PASSWORD_RESET_MAX_LENGTH ?>"
                required
                autofocus
              />
            </div>
            <div class="form-text">কমপক্ষে <?= h(bnDigits((string) PASSWORD_RESET_MIN_LENGTH)) ?> অক্ষর।</div>
          </div>
          <div class="mb-4">
            <label for="confirmPassword" class="form-label fw-semibold">পাসওয়ার্ড আবার লিখুন</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
              <input
                id="confirmPassword"
                name="confirm_password"
                type="password"
                class="form-control"
                autocomplete="new-password"
                maxlength="<?= PASSWORD_RESET_MAX_LENGTH ?>"
                required
              />
              <div class="invalid-feedback" id="confirmFeedback">দুটি পাসওয়ার্ড মিলছে না।</div>
            </div>
          </div>
          <div class="d-grid">
            <button type="submit" class="btn slogin-submit">
              <i class="bi bi-save me-1" aria-hidden="true"></i> পাসওয়ার্ড সংরক্ষণ করুন
            </button>
          </div>
        </form>
        <script>
          (() => {
            'use strict';
            const form = document.getElementById('newPasswordForm');
            const first = document.getElementById('newPassword');
            const second = document.getElementById('confirmPassword');
            const sync = () => second.classList.toggle('is-invalid', second.value !== '' && second.value !== first.value);
            first.addEventListener('input', sync);
            second.addEventListener('input', sync);
            form.addEventListener('submit', (event) => {
              if (first.value.length < <?= PASSWORD_RESET_MIN_LENGTH ?> || first.value !== second.value) {
                event.preventDefault();
                second.classList.toggle('is-invalid', first.value !== second.value);
                (first.value.length < <?= PASSWORD_RESET_MIN_LENGTH ?> ? first : second).focus();
              }
            });
          })();
        </script>
        <?php endif; ?>

        <div class="slogin-foot">
          <p class="mb-0">
            <a href="login-v2.php"><i class="bi bi-arrow-left-short" aria-hidden="true"></i>লগইন পাতায় ফিরে যান</a>
          </p>
        </div>
      </section>
    </main>
  </body>
</html>
