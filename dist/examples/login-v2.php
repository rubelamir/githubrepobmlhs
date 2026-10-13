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
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap"
      rel="stylesheet"
    />
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
      crossorigin="anonymous"
    />
    <link rel="stylesheet" href="../css/adminlte.css" />
    <link rel="stylesheet" href="../css/responsive.css?v=<?= (int) @filemtime(__DIR__ . '/../css/responsive.css') ?>" />
    <style>
      :root {
        --slogin-forest: #1a4d3e;
        --slogin-forest-mid: #246b54;
        --slogin-forest-deep: #0f2f26;
        --slogin-amber: #c4892a;
        --slogin-amber-soft: #e8c078;
      }
      body.slogin-page {
        min-height: 100vh;
        margin: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: clamp(0.75rem, 3vw, 2.5rem);
        font-family: "Hind Siliguri", system-ui, -apple-system, "Segoe UI", sans-serif;
        background:
          radial-gradient(1100px 560px at 12% 8%, rgba(36, 107, 84, 0.18), transparent 62%),
          radial-gradient(900px 520px at 88% 92%, rgba(196, 137, 42, 0.16), transparent 62%),
          var(--bs-body-bg);
      }
      .slogin-card {
        width: min(1040px, 100%);
        display: grid;
        grid-template-columns: 1.02fr 0.98fr;
        border-radius: 1.35rem;
        overflow: hidden;
        background: var(--bs-body-bg);
        border: 1px solid rgba(15, 47, 38, 0.08);
        box-shadow: 0 1.75rem 4.5rem rgba(15, 47, 38, 0.22);
      }
      .slogin-brand {
        position: relative;
        overflow: hidden;
        color: #fff;
        padding: clamp(1.75rem, 3vw, 2.75rem);
        display: flex;
        flex-direction: column;
        gap: 1.15rem;
        background: linear-gradient(155deg, var(--slogin-forest-mid) 0%, var(--slogin-forest) 45%, var(--slogin-forest-deep) 100%);
      }
      .slogin-brand::before {
        content: "";
        position: absolute;
        inset: auto -30% -45% -30%;
        height: 70%;
        background: radial-gradient(circle at 50% 0, rgba(232, 192, 120, 0.26), transparent 66%);
      }
      .slogin-brand::after {
        content: "";
        position: absolute;
        top: -22%;
        right: -18%;
        width: 58%;
        aspect-ratio: 1;
        border-radius: 50%;
        border: 1px solid rgba(255, 255, 255, 0.14);
      }
      .slogin-brand > * {
        position: relative;
        z-index: 1;
      }
      .slogin-emblem {
        width: 104px;
        height: 104px;
        padding: 0.55rem;
        border-radius: 50%;
        background: #fff;
        display: grid;
        place-items: center;
        outline: 3px solid rgba(232, 192, 120, 0.55);
        outline-offset: 4px;
        box-shadow: 0 0.75rem 1.75rem rgba(0, 0, 0, 0.28);
      }
      .slogin-emblem img {
        width: 100%;
        height: 100%;
        object-fit: contain;
      }
      .slogin-emblem span {
        font-size: 2.1rem;
        font-weight: 700;
        color: var(--slogin-forest);
      }
      .slogin-name {
        margin: 0;
        font-size: clamp(1.3rem, 2.4vw, 1.8rem);
        font-weight: 700;
        line-height: 1.4;
      }
      .slogin-name-en {
        margin: 0.35rem 0 0;
        font-size: 0.9rem;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.76);
      }
      .slogin-tag {
        align-self: flex-start;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.3rem 0.8rem;
        border-radius: 999px;
        font-size: 0.76rem;
        letter-spacing: 0.08em;
        font-weight: 600;
        text-transform: uppercase;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.22);
      }
      .slogin-rule {
        height: 1px;
        background: linear-gradient(90deg, rgba(232, 192, 120, 0.65), rgba(255, 255, 255, 0.04));
      }
      .slogin-meta {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        gap: 0.6rem;
        font-size: 0.92rem;
        color: rgba(255, 255, 255, 0.85);
      }
      .slogin-meta li {
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
      }
      .slogin-meta i {
        margin-top: 0.15rem;
        color: var(--slogin-amber-soft);
      }
      .slogin-form {
        padding: clamp(1.75rem, 3vw, 2.75rem);
        display: flex;
        flex-direction: column;
        justify-content: center;
      }
      .slogin-title {
        margin: 0;
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--bs-emphasis-color);
      }
      .slogin-subtitle {
        margin: 0.35rem 0 1.5rem;
        font-size: 0.94rem;
        color: var(--bs-secondary-color);
      }
      .slogin-form .form-control:focus {
        border-color: var(--slogin-forest-mid);
        box-shadow: 0 0 0 0.2rem rgba(36, 107, 84, 0.18);
      }
      .slogin-submit {
        --bs-btn-color: #fff;
        --bs-btn-bg: var(--slogin-forest);
        --bs-btn-border-color: var(--slogin-forest);
        --bs-btn-hover-color: #fff;
        --bs-btn-hover-bg: var(--slogin-forest-deep);
        --bs-btn-hover-border-color: var(--slogin-forest-deep);
        --bs-btn-active-color: #fff;
        --bs-btn-active-bg: var(--slogin-forest-deep);
        --bs-btn-active-border-color: var(--slogin-forest-deep);
        --bs-btn-disabled-color: #fff;
        --bs-btn-disabled-bg: var(--slogin-forest-mid);
        --bs-btn-disabled-border-color: var(--slogin-forest-mid);
        font-weight: 600;
        padding-block: 0.7rem;
      }
      .slogin-foot {
        margin-top: 1.65rem;
        font-size: 0.88rem;
        color: var(--bs-secondary-color);
      }
      .slogin-foot a {
        font-weight: 600;
        text-decoration: none;
        color: var(--slogin-forest-mid);
      }
      .slogin-foot a:hover {
        text-decoration: underline;
      }
      [data-bs-theme="dark"] .slogin-card {
        border-color: rgba(255, 255, 255, 0.08);
        box-shadow: 0 1.75rem 4.5rem rgba(0, 0, 0, 0.55);
      }
      [data-bs-theme="dark"] .slogin-foot a {
        color: var(--slogin-amber-soft);
      }
      @media (max-width: 991.98px) {
        .slogin-card {
          grid-template-columns: 1fr;
        }
        .slogin-brand {
          flex-direction: row;
          align-items: center;
          gap: 1rem;
          flex-wrap: wrap;
        }
        .slogin-emblem {
          width: 74px;
          height: 74px;
        }
        .slogin-brand .slogin-rule,
        .slogin-brand .slogin-meta,
        .slogin-brand .slogin-tag {
          display: none;
        }
      }
    </style>
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
                <?= $mobile === '' ? 'autofocus' : '' ?>
              />
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
            </div>
          </div>

          <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="form-check mb-0">
              <input class="form-check-input" type="checkbox" name="remember" value="1" id="rememberMobile" <?= $remember ? 'checked' : '' ?> />
              <label class="form-check-label" for="rememberMobile">নম্বরটি মনে রাখুন</label>
            </div>
            <a class="link-secondary text-decoration-none small" href="<?= h(publicSiteUrl('contact.php')) ?>">সহায়তা</a>
          </div>

          <div class="d-grid">
            <button type="submit" class="btn slogin-submit" id="loginSubmit">
              <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i> প্রবেশ করুন
            </button>
          </div>
        </form>

        <div class="slogin-foot">
          <p class="mb-1">
            পাসওয়ার্ড ভুলে গেলে প্রধান শিক্ষক অথবা অফিস সহকারীর সাথে যোগাযোগ করুন।
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

        const form = document.getElementById('loginForm');
        const submit = document.getElementById('loginSubmit');
        form?.addEventListener('submit', () => {
          if (!submit) return;
          submit.disabled = true;
          submit.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>প্রবেশ করা হচ্ছে…';
        });
      })();
    </script>
  </body>
</html>
