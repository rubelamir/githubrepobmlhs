<?php
$pageAssets = [];
$pageMeta = ['title' => 'আবেদনকারী লগইন'];
include('header.php');
include('navbar&marqueetag.php');

if (isset($_SESSION['admission_login_id'])) {
    header('Location: view_admission_form');
    exit;
}

$errors = [];
$mobile = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['admission_login_form'])) {
    if (!verifyCsrfToken()) {
        $errors['csrf_error'] = 'নিরাপত্তা টোকেন সঠিক নয়। আবার চেষ্টা করুন।';
    } else {
        if (empty($_REQUEST['mobile'])) {
            $errors['mobile_error'] = 'মোবাইল নম্বর দিন';
        } else {
            $mobile = trim((string) $_REQUEST['mobile']);
        }
        if (empty($_REQUEST['password'])) {
            $errors['password_error'] = 'পাসওয়ার্ড দিন';
        } else {
            $password = trim((string) $_REQUEST['password']);
        }
        if ($errors === []) {
            if (isLoginRateLimited('admission', $mobile)) {
                $errors['mobile_error'] = loginRateLimitMessage('admission', $mobile);
            } else {
                $statement = $conn->prepare('SELECT * FROM students_info WHERE mobile = ? LIMIT 1');
                $statement->bind_param('s', $mobile);
                $statement->execute();
                $row = $statement->get_result()->fetch_assoc();
                $statement->close();
                if ($row && verifyStoredPassword($password, (string) $row['pass'])) {
                    migratePasswordIfLegacy($conn, 'students_info', (int) $row['id'], $password, (string) $row['pass']);
                    clearLoginFailures('admission', $mobile);
                    establishAdmissionSession((int) $row['id']);
                    header('Location: view_admission_form');
                    exit;
                }
                recordLoginFailure('admission', $mobile);
                $errors[$row ? 'password_error' : 'mobile_error'] = $row
                    ? 'পাসওয়ার্ড সঠিক নয়'
                    : 'এই মোবাইল নম্বরে কোনো আবেদন পাওয়া যায়নি';
            }
        }
    }
}

$admHero = [
    'kicker' => 'অনলাইন ভর্তি',
    'title' => 'আবেদনকারী লগইন',
    'text' => 'আবেদনের সময় ব্যবহৃত মোবাইল নম্বর ও পাসওয়ার্ড দিয়ে লগইন করে ফর্ম দেখুন বা সম্পাদনা করুন।',
];
?>
<div class="adm-page">
    <?php include('admission_navigation.php'); ?>
    <section class="adm-wrap">
        <div class="container">
            <div class="adm-auth">
                <div class="adm-auth-card adm-sheet">
                    <h2>লগইন করুন</h2>
                    <p class="adm-auth-lead">শুধু নিজের আবেদন দেখতে ও সংশোধন করতে এই লগইন ব্যবহার করুন।</p>
                    <form method="post" class="adm-form" novalidate>
                        <?= csrfField() ?>
                        <?php if (isset($errors['csrf_error'])): ?>
                            <div class="alert alert-danger py-2"><?= h($errors['csrf_error']) ?></div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label for="mobile" class="form-label">মোবাইল নম্বর</label>
                            <input type="text" class="form-control<?= isset($errors['mobile_error']) ? ' is-invalid' : '' ?>" id="mobile" name="mobile" value="<?= h($mobile) ?>" placeholder="০১XXXXXXXXX" autocomplete="username" required>
                            <div class="invalid-feedback"><?= h((string) ($errors['mobile_error'] ?? '')) ?></div>
                        </div>
                        <div class="mb-3">
                            <label for="admission_login_password" class="form-label">পাসওয়ার্ড</label>
                            <input type="password" class="form-control<?= isset($errors['password_error']) ? ' is-invalid' : '' ?>" id="admission_login_password" name="password" placeholder="পাসওয়ার্ড লিখুন" autocomplete="current-password" required>
                            <div class="invalid-feedback"><?= h((string) ($errors['password_error'] ?? '')) ?></div>
                        </div>
                        <div class="adm-auth-actions">
                            <button type="submit" name="admission_login_form" class="btn">লগইন</button>
                        </div>
                    </form>
                    <div class="adm-auth-links">
                        <a href="admission">নতুন আবেদন</a>
                        <a href="admission_forget_password">পাসওয়ার্ড ভুলে গেছেন?</a>
                        <a href="admission_reset_password">পাসওয়ার্ড পরিবর্তন</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include('footer.php'); ?>
