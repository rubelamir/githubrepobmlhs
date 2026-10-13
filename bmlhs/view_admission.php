<?php
$pageAssets = [];
$pageMeta = ['title' => 'আবেদনকারীর তালিকা'];
include('header.php');
include('navbar&marqueetag.php');

$errors = [];
$cls = '';
$gender = '';
$rows = [];
$searched = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['student_search'])) {
    if (!verifyCsrfToken()) {
        $errors['csrf_error'] = 'নিরাপত্তা টোকেন সঠিক নয়। আবার চেষ্টা করুন।';
    } else {
        if (empty($_REQUEST['cls'])) {
            $errors['cls_error'] = 'শ্রেণী নির্বাচন করুন';
        } else {
            $cls = mysqli_real_escape_string($conn, test_input($_REQUEST['cls']));
        }
        if (empty($_REQUEST['gender'])) {
            $errors['gender_error'] = 'ছাত্র/ছাত্রী নির্বাচন করুন';
        } else {
            $gender = mysqli_real_escape_string($conn, test_input($_REQUEST['gender']));
        }
    }
}

if ($errors === [] && $cls !== '' && $gender !== '') {
    $searched = true;
    $date = date('Y');
    if ($gender === 'all') {
        $sql = "SELECT students_info.*, cls.class_name_ban FROM students_info JOIN cls ON students_info.cls = cls.id LEFT JOIN sections ON CAST(students_info.sections AS CHAR) = CAST(sections.id AS CHAR) WHERE students_info.session_year = ? AND students_info.cls = ? AND students_info.sections = 'others' ORDER BY " . studentsListOrderSql('students_info', 'cls', 'sections');
        $statement = $conn->prepare($sql);
        $statement->bind_param('ss', $date, $cls);
    } else {
        $sql = "SELECT students_info.*, cls.class_name_ban FROM students_info JOIN cls ON students_info.cls = cls.id LEFT JOIN sections ON CAST(students_info.sections AS CHAR) = CAST(sections.id AS CHAR) WHERE students_info.session_year = ? AND students_info.cls = ? AND students_info.gender = ? AND students_info.sections = 'others' ORDER BY " . studentsListOrderSql('students_info', 'cls', 'sections');
        $statement = $conn->prepare($sql);
        $statement->bind_param('sss', $date, $cls, $gender);
    }
    $statement->execute();
    $query = $statement->get_result();
    while ($query instanceof mysqli_result && ($row = $query->fetch_assoc())) {
        $rows[] = $row;
    }
    $statement->close();
}

$admHero = [
    'kicker' => 'অনলাইন ভর্তি',
    'title' => 'আবেদনকারীর তালিকা',
    'text' => 'চলতি শিক্ষাবর্ষে ভর্তির জন্য জমা পড়া প্রাথমিক আবেদনের তালিকা।',
];
?>
<div class="adm-page">
    <?php include('admission_navigation.php'); ?>
    <section class="adm-wrap">
        <div class="container">
            <div class="adm-list-filter adm-sheet">
                <form method="post" class="adm-form" novalidate>
                    <?= csrfField() ?>
                    <?php if (isset($errors['csrf_error'])): ?>
                        <div class="alert alert-danger py-2"><?= h($errors['csrf_error']) ?></div>
                    <?php endif; ?>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label for="cls" class="form-label">শ্রেণী</label>
                            <select class="form-select<?= isset($errors['cls_error']) ? ' is-invalid' : '' ?>" id="cls" name="cls" required>
                                <option value="">শ্রেণী নির্বাচন করুন</option>
                                <?php echoClassOptions(admissionClasses(publicDatabase()), $cls); ?>
                            </select>
                            <div class="invalid-feedback"><?= h((string) ($errors['cls_error'] ?? '')) ?></div>
                        </div>
                        <div class="col-md-4">
                            <label for="gender" class="form-label">ছাত্র / ছাত্রী</label>
                            <select class="form-select<?= isset($errors['gender_error']) ? ' is-invalid' : '' ?>" id="gender" name="gender" required>
                                <option value="">নির্বাচন করুন</option>
                                <option value="male"<?= $gender === 'male' ? ' selected' : '' ?>>ছাত্র</option>
                                <option value="female"<?= $gender === 'female' ? ' selected' : '' ?>>ছাত্রী</option>
                                <option value="all"<?= $gender === 'all' ? ' selected' : '' ?>>সকল</option>
                            </select>
                            <div class="invalid-feedback"><?= h((string) ($errors['gender_error'] ?? '')) ?></div>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" name="student_search" class="btn w-100">তালিকা দেখুন</button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($searched && $rows === []): ?>
                <div class="adm-empty">এই শর্তে কোনো আবেদন পাওয়া যায়নি।</div>
            <?php elseif ($rows !== []): ?>
                <div class="adm-list-table-wrap">
                    <table class="adm-list-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>শ্রেণী</th>
                                <th>নাম</th>
                                <th>পিতার নাম</th>
                                <th>মাতার নাম</th>
                                <th>জন্ম তারিখ</th>
                                <th>ছাত্র/ছাত্রী</th>
                                <th>মোবাইল</th>
                                <th>ছবি</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $index => $row): ?>
                                <?php
                                $photo = studentPhotoUrl((string) ($row['student_pic'] ?? ''));
                                $birth = publicStaffDate($row['birth_date'] ?? null);
                                $genderLabel = (($row['gender'] ?? '') === 'male') ? 'ছাত্র' : ((($row['gender'] ?? '') === 'female') ? 'ছাত্রী' : '—');
                                ?>
                                <tr>
                                    <td><?= h(english_to_bengali((string) ($index + 1))) ?></td>
                                    <td><?= h((string) ($row['class_name_ban'] ?? '')) ?></td>
                                    <td><?= h((string) ($row['name_ban'] ?? '')) ?></td>
                                    <td><?= h((string) ($row['fname_ban'] ?? '')) ?></td>
                                    <td><?= h((string) ($row['mname_ban'] ?? '')) ?></td>
                                    <td><?= h($birth !== '' ? english_to_bengali($birth) : '—') ?></td>
                                    <td><?= h($genderLabel) ?></td>
                                    <td><?= h(english_to_bengali(publicStaffMobileDisplay((string) ($row['mobile'] ?? '')))) ?></td>
                                    <td><img src="<?= h($photo) ?>" alt="" width="52" height="64"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php include('footer.php'); ?>
