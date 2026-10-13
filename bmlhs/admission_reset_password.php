<?php
$pageAssets = [];
$pageMeta = ['title' => 'পাসওয়ার্ড পরিবর্তন'];
include('header.php');
include('navbar&marqueetag.php');
if (isset($_SESSION['admission_login_id'])) {
    header('Location: view_admission_form');
    exit;
}

$errors = [];
$mobile = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['reset_password'])) {
    if (!verifyCsrfToken()) {
        $errors['csrf_error'] = 'নিরাপত্তা টোকেন সঠিক নয়। আবার চেষ্টা করুন।';
    } else {
        $mobile = trim((string) ($_REQUEST['mobile'] ?? ''));
        $password = trim((string) ($_REQUEST['password'] ?? ''));
        $new_password = (string) ($_REQUEST['new_password'] ?? '');
        $confirm_password = (string) ($_REQUEST['confirm_password'] ?? '');

        if ($mobile === '') {
            $errors['mobile_error'] = 'মোবাইল নম্বর দিন';
        }
        if ($password === '') {
            $errors['password_error'] = 'বর্তমান পাসওয়ার্ড দিন';
        }
        if ($new_password === '') {
            $errors['new_password_error'] = 'নতুন পাসওয়ার্ড দিন';
        }
        if ($confirm_password === '') {
            $errors['confirm_password_error'] = 'কনফার্ম পাসওয়ার্ড দিন';
        }

        if ($errors === []) {
            $statement = $conn->prepare('SELECT * FROM students_info WHERE mobile = ? LIMIT 1');
            $statement->bind_param('s', $mobile);
            $statement->execute();
            $row = $statement->get_result()->fetch_assoc();
            $statement->close();

            if (!$row || !verifyStoredPassword($password, (string) $row['pass'])) {
                $errors['password_error'] = 'মোবাইল নম্বর বা বর্তমান পাসওয়ার্ড সঠিক নয়';
            } elseif ($new_password !== $confirm_password) {
                $errors['confirm_password_error'] = 'নতুন পাসওয়ার্ড মিলছে না';
            } elseif (mb_strlen($new_password, 'UTF-8') < 5) {
                $errors['new_password_error'] = 'নতুন পাসওয়ার্ড কমপক্ষে ৫ অক্ষরের হবে';
            } else {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $updateSucceeded = false;
                $update = null;
                try {
                    $update = $conn->prepare('UPDATE students_info SET pass = ? WHERE id = ?');
                    $update->bind_param('si', $new_password_hash, $row['id']);
                    $updateSucceeded = $update->execute();
                    if (!$updateSucceeded) {
                        error_log('Admission password change failed: ' . $update->error);
                    }
                } catch (mysqli_sql_exception $exception) {
                    error_log('Admission password change failed: ' . $exception->getMessage());
                } finally {
                    if ($update instanceof mysqli_stmt) {
                        $update->close();
                    }
                }
                if ($updateSucceeded) {
                    $_SESSION['msg'] = 'পাসওয়ার্ড সফলভাবে পরিবর্তন হয়েছে। এখন নতুন পাসওয়ার্ড দিয়ে লগইন করুন।';
                } else {
                    $errors['new_password_error'] = 'পাসওয়ার্ড সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।';
                }
            }
        }
    }
}

$admHero = [
    'kicker' => 'অনলাইন ভর্তি',
    'title' => 'পাসওয়ার্ড পরিবর্তন',
    'text' => 'বর্তমান পাসওয়ার্ড জানা থাকলে এখান থেকে নতুন পাসওয়ার্ড সেট করুন।',
];
$success = trim((string) ($_SESSION['msg'] ?? ''));
unset($_SESSION['msg']);
?>
<div class="adm-page">
    <?php include('admission_navigation.php'); ?>
    <section class="adm-wrap">
        <div class="container">
            <div class="adm-auth">
                <div class="adm-auth-card adm-sheet">
                    <?php if ($success !== ''): ?>
                        <h2>পাসওয়ার্ড পরিবর্তিত হয়েছে</h2>
                        <p class="adm-auth-lead"><?= h($success) ?></p>
                        <div class="adm-auth-actions">
                            <a class="btn" href="admission_login">লগইন করুন</a>
                        </div>
                    <?php else: ?>
                        <h2>নতুন পাসওয়ার্ড সেট করুন</h2>
                        <p class="adm-auth-lead">মোবাইল, বর্তমান পাসওয়ার্ড এবং কমপক্ষে ৫ অক্ষরের নতুন পাসওয়ার্ড দিন।</p>
                        <form method="post" class="adm-form" novalidate>
                            <?= csrfField() ?>
                            <?php if (isset($errors['csrf_error'])): ?>
                                <div class="alert alert-danger py-2"><?= h($errors['csrf_error']) ?></div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label for="mobile" class="form-label">মোবাইল নম্বর</label>
                                <input type="text" class="form-control<?= isset($errors['mobile_error']) ? ' is-invalid' : '' ?>" id="mobile" name="mobile" value="<?= h($mobile) ?>" autocomplete="username" required>
                                <div class="invalid-feedback"><?= h((string) ($errors['mobile_error'] ?? '')) ?></div>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">বর্তমান পাসওয়ার্ড</label>
                                <input type="password" class="form-control<?= isset($errors['password_error']) ? ' is-invalid' : '' ?>" id="password" name="password" autocomplete="current-password" required>
                                <div class="invalid-feedback"><?= h((string) ($errors['password_error'] ?? '')) ?></div>
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">নতুন পাসওয়ার্ড</label>
                                <input type="password" class="form-control<?= isset($errors['new_password_error']) ? ' is-invalid' : '' ?>" id="new_password" name="new_password" autocomplete="new-password" minlength="5" required>
                                <div class="invalid-feedback"><?= h((string) ($errors['new_password_error'] ?? '')) ?></div>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">নতুন পাসওয়ার্ড নিশ্চিত করুন</label>
                                <input type="password" class="form-control<?= isset($errors['confirm_password_error']) ? ' is-invalid' : '' ?>" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="5" required>
                                <div class="invalid-feedback"><?= h((string) ($errors['confirm_password_error'] ?? '')) ?></div>
                            </div>
                            <div class="adm-auth-actions">
                                <button type="submit" name="reset_password" class="btn">পাসওয়ার্ড পরিবর্তন</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    <div class="adm-auth-links">
                        <a href="admission_login">লগইনে ফিরুন</a>
                        <a href="admission_forget_password">পাসওয়ার্ড ভুলে গেছেন?</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include('footer.php'); ?>
