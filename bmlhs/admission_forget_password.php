<?php
$pageAssets = [];
$pageMeta = ['title' => 'পাসওয়ার্ড পুনরুদ্ধার'];
include('header.php');
include('navbar&marqueetag.php');

if (isset($_SESSION['admission_login_id'])) {
    header('Location: view_admission_form');
    exit;
}

$errors = [];
$msg = '';
$mobile = '';
$birth_reg_no = '';
$resetStudentId = (int) ($_SESSION['admission_reset_student_id'] ?? 0);
$showNewPasswordForm = $resetStudentId > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['admission_forget_password'])) {
    if (!verifyCsrfToken()) {
        $errors['csrf_error'] = 'নিরাপত্তা টোকেন সঠিক নয়। আবার চেষ্টা করুন।';
    } else {
        $mobile = trim((string) ($_REQUEST['mobile'] ?? ''));
        $birth_reg_no = trim((string) ($_REQUEST['birth_reg_no'] ?? ''));
        if ($mobile === '') {
            $errors['mobile_error'] = 'মোবাইল নম্বর দিন';
        }
        if ($birth_reg_no === '') {
            $errors['birth_reg_no_error'] = 'জন্ম নিবন্ধন নম্বর দিন';
        }
        if ($errors === []) {
            $statement = $conn->prepare('SELECT id, birth_reg_no FROM students_info WHERE mobile = ? LIMIT 1');
            $statement->bind_param('s', $mobile);
            $statement->execute();
            $row = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!$row) {
                $errors['mobile_error'] = 'এই মোবাইল নম্বরে আবেদন পাওয়া যায়নি';
            } elseif ((string) $row['birth_reg_no'] !== $birth_reg_no) {
                $errors['birth_reg_no_error'] = 'জন্ম নিবন্ধন নম্বর মিলছে না';
            } else {
                $_SESSION['admission_reset_student_id'] = (int) $row['id'];
                $_SESSION['admission_reset_mobile'] = $mobile;
                unset($_SESSION['admission_reset_verified'], $_SESSION['admission_reset_otp_hash'], $_SESSION['admission_reset_otp_expires'], $_SESSION['admission_reset_otp_tries']);
                $resetStudentId = (int) $row['id'];
                if (smsGatewayReady(publicDatabase())) {
                    $otp = (string) random_int(100000, 999999);
                    $_SESSION['admission_reset_otp_hash'] = password_hash($otp, PASSWORD_DEFAULT);
                    $_SESSION['admission_reset_otp_expires'] = time() + 600;
                    $schoolName = (string) (currentSchool()['school_name_bn'] ?? 'বিদ্যালয়');
                    $sent = sendSchoolSms(
                        publicDatabase(),
                        $mobile,
                        $schoolName . ' পাসওয়ার্ড রিসেট কোড: ' . $otp . '। ১০ মিনিটের মধ্যে ব্যবহার করুন।',
                        'password_reset'
                    );
                    if (empty($sent['ok'])) {
                        unset($_SESSION['admission_reset_student_id'], $_SESSION['admission_reset_mobile'], $_SESSION['admission_reset_otp_hash'], $_SESSION['admission_reset_otp_expires']);
                        $resetStudentId = 0;
                        $showNewPasswordForm = false;
                        $errors['mobile_error'] = 'এসএমএস পাঠানো যায়নি। একটু পরে আবার চেষ্টা করুন।';
                    } else {
                        $showNewPasswordForm = false;
                    }
                } else {
                    unset($_SESSION['admission_reset_student_id'], $_SESSION['admission_reset_mobile']);
                    $resetStudentId = 0;
                    $showNewPasswordForm = false;
                    $errors['mobile_error'] = 'এসএমএস গেটওয়ে চালু না থাকায় পাসওয়ার্ড রিসেট করা যাচ্ছে না। বিদ্যালয়ে যোগাযোগ করুন, অথবা পুরনো পাসওয়ার্ড জানা থাকলে সেটি দিয়ে পরিবর্তন করুন।';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['admission_verify_otp'])) {
    if (!verifyCsrfToken()) {
        $errors['csrf_error'] = 'নিরাপত্তা টোকেন সঠিক নয়। আবার চেষ্টা করুন।';
    } elseif ($resetStudentId < 1) {
        $errors['otp_error'] = 'প্রথমে মোবাইল ও জন্ম নিবন্ধন যাচাই করুন।';
    } else {
        $otp = asciiDigits(trim((string) ($_REQUEST['otp'] ?? '')));
        $hash = $_SESSION['admission_reset_otp_hash'] ?? '';
        $expires = (int) ($_SESSION['admission_reset_otp_expires'] ?? 0);
        $tries = (int) ($_SESSION['admission_reset_otp_tries'] ?? 0);
        if (!is_string($hash) || $hash === '' || $expires < time()) {
            unset($_SESSION['admission_reset_student_id'], $_SESSION['admission_reset_otp_hash'], $_SESSION['admission_reset_otp_expires']);
            $resetStudentId = 0;
            $errors['otp_error'] = 'কোডের মেয়াদ শেষ। আবার যাচাই করুন।';
        } elseif ($tries >= 5) {
            unset($_SESSION['admission_reset_student_id'], $_SESSION['admission_reset_otp_hash']);
            $resetStudentId = 0;
            $errors['otp_error'] = 'অনেকবার ভুল কোড দেওয়া হয়েছে। আবার যাচাই করুন।';
        } elseif (!password_verify($otp, $hash)) {
            $_SESSION['admission_reset_otp_tries'] = $tries + 1;
            $errors['otp_error'] = 'কোডটি সঠিক নয়।';
        } else {
            $_SESSION['admission_reset_verified'] = 1;
            unset($_SESSION['admission_reset_otp_hash'], $_SESSION['admission_reset_otp_expires'], $_SESSION['admission_reset_otp_tries']);
            $showNewPasswordForm = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['admission_set_new_password'])) {
    if (!verifyCsrfToken()) {
        $errors['csrf_error'] = 'নিরাপত্তা টোকেন সঠিক নয়। আবার চেষ্টা করুন।';
    } elseif ($resetStudentId < 1 || empty($_SESSION['admission_reset_verified'])) {
        $errors['csrf_error'] = 'প্রথমে মোবাইলে পাঠানো কোড দিয়ে যাচাই করুন।';
        $showNewPasswordForm = false;
    } else {
        $new_password = (string) ($_REQUEST['new_password'] ?? '');
        $confirm_password = (string) ($_REQUEST['confirm_password'] ?? '');
        if ($new_password === '') {
            $errors['new_password_error'] = 'নতুন পাসওয়ার্ড দিন';
        } elseif (mb_strlen($new_password, 'UTF-8') < 5) {
            $errors['new_password_error'] = 'পাসওয়ার্ড কমপক্ষে পাঁচ অক্ষরের হবে';
        }
        if ($confirm_password === '') {
            $errors['confirm_password_error'] = 'কনফার্ম পাসওয়ার্ড দিন';
        } elseif ($new_password !== $confirm_password) {
            $errors['confirm_password_error'] = 'পাসওয়ার্ড মিলছে না';
        }
        if ($errors === []) {
            $hash = hashPassword($new_password);
            $update = $conn->prepare('UPDATE students_info SET pass = ? WHERE id = ?');
            $update->bind_param('si', $hash, $resetStudentId);
            if ($update->execute()) {
                $resetMobile = (string) ($_SESSION['admission_reset_mobile'] ?? '');
                unset($_SESSION['admission_reset_student_id'], $_SESSION['admission_reset_verified'], $_SESSION['admission_reset_mobile'], $_SESSION['admission_reset_otp_hash']);
                $showNewPasswordForm = false;
                if ($resetMobile !== '' && smsGatewayReady(publicDatabase())) {
                    $schoolName = (string) (currentSchool()['school_name_bn'] ?? 'বিদ্যালয়');
                    sendSchoolSms(publicDatabase(), $resetMobile, $schoolName . ' ভর্তি পাসওয়ার্ড পরিবর্তন হয়েছে। আপনি না করলে বিদ্যালয়ে জানান।', 'password_reset');
                }
                $msg = 'পাসওয়ার্ড সফলভাবে পরিবর্তন হয়েছে। এখন লগইন করুন।';
            } else {
                $errors['new_password_error'] = 'পাসওয়ার্ড সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।';
                $showNewPasswordForm = true;
            }
            $update->close();
        } else {
            $showNewPasswordForm = true;
        }
    }
}

$smsReady = smsGatewayReady(publicDatabase());
$showOtpForm = $smsReady && $resetStudentId > 0 && empty($_SESSION['admission_reset_verified']) && $msg === '';
if ($showOtpForm) {
    $showNewPasswordForm = false;
}

$admHero = [
    'kicker' => 'অনলাইন ভর্তি',
    'title' => 'পাসওয়ার্ড পুনরুদ্ধার',
    'text' => 'মোবাইল ও জন্ম নিবন্ধন যাচাই করে নতুন পাসওয়ার্ড সেট করুন।',
];
?>
<div class="adm-page">
    <?php include('admission_navigation.php'); ?>
    <section class="adm-wrap">
        <div class="container">
            <div class="adm-auth">
                <div class="adm-auth-card adm-sheet">
                    <?php if ($msg !== ''): ?>
                        <h2>পাসওয়ার্ড পরিবর্তিত হয়েছে</h2>
                        <p class="adm-auth-lead"><?= h($msg) ?></p>
                        <div class="adm-auth-actions">
                            <a class="btn" href="admission_login">লগইন করুন</a>
                        </div>
                    <?php elseif ($showOtpForm): ?>
                        <h2>এসএমএস কোড</h2>
                        <p class="adm-auth-lead">মোবাইলে পাঠানো ৬ সংখ্যার কোডটি লিখুন। কোডটি ১০ মিনিট পর্যন্ত কার্যকর।</p>
                        <form method="post" class="adm-form" novalidate>
                            <?= csrfField() ?>
                            <?php if (isset($errors['csrf_error'])): ?>
                                <div class="alert alert-danger py-2"><?= h($errors['csrf_error']) ?></div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label for="otp" class="form-label">রিসেট কোড</label>
                                <input type="text" class="form-control<?= isset($errors['otp_error']) ? ' is-invalid' : '' ?>" id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" required>
                                <div class="invalid-feedback"><?= h((string) ($errors['otp_error'] ?? '')) ?></div>
                            </div>
                            <div class="adm-auth-actions">
                                <button type="submit" name="admission_verify_otp" class="btn">কোড যাচাই করুন</button>
                            </div>
                        </form>
                    <?php elseif (!$showNewPasswordForm): ?>
                        <h2>পরিচয় যাচাই</h2>
                        <p class="adm-auth-lead">আবেদনের মোবাইল নম্বর ও জন্ম নিবন্ধন নম্বর দিন।</p>
                        <form method="post" class="adm-form" novalidate>
                            <?= csrfField() ?>
                            <?php if (isset($errors['csrf_error'])): ?>
                                <div class="alert alert-danger py-2"><?= h($errors['csrf_error']) ?></div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label for="mobile" class="form-label">মোবাইল নম্বর</label>
                                <input type="text" class="form-control<?= isset($errors['mobile_error']) ? ' is-invalid' : '' ?>" id="mobile" name="mobile" value="<?= h($mobile) ?>" placeholder="০১XXXXXXXXX" autocomplete="off" required>
                                <div class="invalid-feedback"><?= h((string) ($errors['mobile_error'] ?? '')) ?></div>
                            </div>
                            <div class="mb-3">
                                <label for="birth_reg_no" class="form-label">জন্ম নিবন্ধন নম্বর</label>
                                <input type="text" class="form-control<?= isset($errors['birth_reg_no_error']) ? ' is-invalid' : '' ?>" id="birth_reg_no" name="birth_reg_no" value="<?= h($birth_reg_no) ?>" placeholder="জন্ম সনদের নম্বর" autocomplete="off" required>
                                <div class="invalid-feedback"><?= h((string) ($errors['birth_reg_no_error'] ?? '')) ?></div>
                            </div>
                            <div class="adm-auth-actions">
                                <button type="submit" name="admission_forget_password" class="btn">যাচাই করুন</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <h2>নতুন পাসওয়ার্ড</h2>
                        <p class="adm-auth-lead">পরিচয় যাচাই হয়েছে। কমপক্ষে ৫ অক্ষরের নতুন পাসওয়ার্ড দিন।</p>
                        <form method="post" class="adm-form" novalidate>
                            <?= csrfField() ?>
                            <?php if (isset($errors['csrf_error'])): ?>
                                <div class="alert alert-danger py-2"><?= h($errors['csrf_error']) ?></div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">নতুন পাসওয়ার্ড</label>
                                <input type="password" class="form-control<?= isset($errors['new_password_error']) ? ' is-invalid' : '' ?>" id="new_password" name="new_password" autocomplete="new-password" minlength="5" required>
                                <div class="invalid-feedback"><?= h((string) ($errors['new_password_error'] ?? '')) ?></div>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">পাসওয়ার্ড নিশ্চিত করুন</label>
                                <input type="password" class="form-control<?= isset($errors['confirm_password_error']) ? ' is-invalid' : '' ?>" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="5" required>
                                <div class="invalid-feedback"><?= h((string) ($errors['confirm_password_error'] ?? '')) ?></div>
                            </div>
                            <div class="adm-auth-actions">
                                <button type="submit" name="admission_set_new_password" class="btn">পাসওয়ার্ড সেট করুন</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    <div class="adm-auth-links">
                        <a href="admission_login">লগইনে ফিরুন</a>
                        <a href="admission_reset_password">পুরনো পাসওয়ার্ড জানা থাকলে পরিবর্তন করুন</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include('footer.php'); ?>
