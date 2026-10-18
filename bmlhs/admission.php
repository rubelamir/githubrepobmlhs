<?php
$pageAssets = ['jquery'];
include('header.php');
if (!empty($_SESSION['admission_login_id'])) {
    header('Location: view_admission_form');
    exit;
}
include('navbar&marqueetag.php');
?>



<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
<?php
$errors = array();
$district = '';
$upazilla = '';
$last_class = '';
$pictureData = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_REQUEST['admission_submit']) && !verifyCsrfToken()) {
$errors["csrf_error"] = "Invalid security token. Please try again.";
} elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_REQUEST['admission_submit'])) {
if (empty($_REQUEST["admission_token"])) {
$errors["admission_token_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$admission_token = mysqli_real_escape_string($conn, test_input($_REQUEST["admission_token"]));
$statement = $conn->prepare('SELECT id FROM students_info WHERE admission_token = ? LIMIT 1');
$statement->bind_param('s', $admission_token);
$statement->execute();
$rowCount = $statement->get_result()->num_rows;
if ($rowCount > 0) {
$errors["admission_token_error"] = "এই টোকেনটি ইতিমধ্যেই ব্যবহৃত হয়েছে";
}
$statement = $conn->prepare('SELECT token, token_status FROM token WHERE token = ? LIMIT 1');
$statement->bind_param('s', $admission_token);
$statement->execute();
$query = $statement->get_result();
$token_count = $query->num_rows;
if ($token_count > 0) {
$row = mysqli_fetch_assoc($query);
if (($row["token"] == $admission_token) && ($row["token_status"] == 1)) {
$errors["admission_token_error"] = "এই টোকেনটি ইতিমধ্যেই ব্যবহৃত হয়েছে";
}
} else {
$errors["admission_token_error"] = "টোকেনটি সঠিক নয়";
}
}
if (empty($_REQUEST["cls"])) {
$errors["cls_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$cls = mysqli_real_escape_string($conn, test_input($_REQUEST["cls"]));
if (!isAdmissionClass(publicDatabase(), $cls)) {
$errors["cls_error"] = "এই শ্রেণীতে এখন ভর্তি চলছে না";
}
}
if (empty($_REQUEST["session_year"])) {
$errors["session_year_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$session_year = mysqli_real_escape_string($conn, test_input($_REQUEST["session_year"]));
}
if (empty($_REQUEST["name_eng"])) {
$errors["name_eng_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$name_eng = mysqli_real_escape_string($conn, test_input($_REQUEST["name_eng"]));
$name_eng = ucwords(strtolower($name_eng));
if (!preg_match("/^[a-zA-Z-'. ]*$/", $name_eng)) {
$errors["name_eng_error"] = "Only English letters, dot & white space allowed";
}
}
if (empty($_REQUEST["name_ban"])) {
$errors["name_ban_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$name_ban = mysqli_real_escape_string($conn, test_input($_REQUEST["name_ban"]));
if (preg_match("/^[a-zA-Z0-9 .]*$/", $name_ban)) {
$errors["name_ban_error"] = "বাংলায় লিখুন";
}
}
if (empty($_REQUEST["fname_eng"])) {
$errors["fname_eng_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$fname_eng = mysqli_real_escape_string($conn, test_input($_REQUEST["fname_eng"]));
$fname_eng = ucwords(strtolower($fname_eng));
if (!preg_match("/^[a-zA-Z-'. ]*$/", $fname_eng)) {
$errors["fname_eng_error"] = "Only English letters, dot & white space allowed";
}
}
if (empty($_REQUEST["fname_ban"])) {
$errors["fname_ban_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$fname_ban = mysqli_real_escape_string($conn, test_input($_REQUEST["fname_ban"]));
if (preg_match("/^[a-zA-Z0-9 .]*$/", $fname_ban)) {
$errors["fname_ban_error"] = "বাংলায় লিখুন";
}
}
$father_nid_no = mysqli_real_escape_string($conn, test_input((string) ($_REQUEST["father_nid_no"] ?? '')));
if ($father_nid_no !== '' && !in_array(strlen($father_nid_no), [10, 13, 17], true)) {
    $errors["father_nid_no_error"] = "জাতীয় পরিচয় পত্র নাম্বার 10/13/17 সংখ্যার হয়। আপনি লিখেছেন " . strlen($father_nid_no) . " সংখ্যা";
}
if (empty($_REQUEST["mname_eng"])) {
$errors["mname_eng_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$mname_eng = mysqli_real_escape_string($conn, test_input($_REQUEST["mname_eng"]));
$mname_eng = ucwords(strtolower($mname_eng));
if (!preg_match("/^[a-zA-Z-'. ]*$/", $mname_eng)) {
$errors["mname_eng_error"] = "Only English letters, dot & white space allowed";
}
}
if (empty($_REQUEST["mname_ban"])) {
$errors["mname_ban_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$mname_ban = mysqli_real_escape_string($conn, test_input($_REQUEST["mname_ban"]));
if (preg_match("/^[a-zA-Z0-9 .]*$/", $mname_ban)) {
$errors["mname_ban_error"] = "বাংলায় লিখুন";
}
}
$mother_nid_no = mysqli_real_escape_string($conn, test_input((string) ($_REQUEST["mother_nid_no"] ?? '')));
if ($mother_nid_no !== '' && !in_array(strlen($mother_nid_no), [10, 13, 17], true)) {
    $errors["mother_nid_no_error"] = "জাতীয় পরিচয় পত্র নাম্বার 10/13/17 সংখ্যার হয়। আপনি লিখেছেন " . strlen($mother_nid_no) . " সংখ্যা";
}
if (empty($_REQUEST["gender"])) {
$errors["gender_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$gender = mysqli_real_escape_string($conn, test_input($_REQUEST["gender"]));
}
if (empty($_REQUEST["religion"])) {
$errors["religion_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$religion = mysqli_real_escape_string($conn, test_input($_REQUEST["religion"]));
}
if (empty($_REQUEST["birth_date"])) {
$errors["birth_date_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$birth_date = mysqli_real_escape_string($conn, test_input($_REQUEST["birth_date"]));
}
if (empty($_REQUEST["birth_reg_no"])) {
$errors["birth_reg_no_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$birth_reg_no = mysqli_real_escape_string($conn, test_input($_REQUEST["birth_reg_no"]));
if (strlen($birth_reg_no) != "17") {
$errors["birth_reg_no_error"] = "জন্ম নিবন্ধন নাম্বার 17 সংখ্যার হয়। আপনি লিখেছেন " . strlen($birth_reg_no) . " সংখ্যা";
}
$statement = $conn->prepare('SELECT id FROM students_info WHERE birth_reg_no = ? LIMIT 1');
$statement->bind_param('s', $birth_reg_no);
$statement->execute();
$rowCount = $statement->get_result()->num_rows;
if ($rowCount > 0) {
$errors["birth_reg_no_error"] = "ইতি পূর্বে এই জন্ম নিবন্ধন নাম্বার ব্যবহৃত হয়েছে";
}
}
if (empty($_REQUEST["division"])) {
$errors["division_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$division = (int) $_REQUEST["division"];
if ($division <= 0) {
    $errors["division_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
}
}
if (empty($_REQUEST["district"])) {
$errors["district_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$district = (int) $_REQUEST["district"];
if ($district <= 0) {
    $errors["district_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
}
}
if (empty($_REQUEST["upazilla"])) {
$errors["upazilla_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$upazilla = (int) $_REQUEST["upazilla"];
if ($upazilla <= 0) {
    $errors["upazilla_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
}
}
if (empty($_REQUEST["post_office"])) {
$errors["post_office_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$post_office = mysqli_real_escape_string($conn, test_input($_REQUEST["post_office"]));
if (preg_match("/^[a-zA-Z0-9 .]*$/", $post_office)) {
$errors["post_office_error"] = "বাংলায় লিখুন";
}
}
if (empty($_REQUEST["village"])) {
$errors["village_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$village = mysqli_real_escape_string($conn, test_input($_REQUEST["village"]));
if (preg_match("/^[a-zA-Z0-9 .]*$/", $village)) {
$errors["village_error"] = "বাংলায় লিখুন";
}
}
if (empty($_REQUEST["house_name"])) {
$errors["house_name_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$house_name = mysqli_real_escape_string($conn, test_input($_REQUEST["house_name"]));
if (preg_match("/^[a-zA-Z0-9 .]*$/", $house_name)) {
$errors["house_name_error"] = "বাংলায় লিখুন";
}
}
if (empty($_REQUEST["last_class"])) {
$errors["last_class_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$last_class = mysqli_real_escape_string($conn, test_input($_REQUEST["last_class"]));
if (preg_match("/^[a-zA-Z0-9 .]*$/", $last_class)) {
$errors["last_class_error"] = "বাংলায় লিখুন";
}
}
if (empty($_REQUEST["passing_year"])) {
$errors["passing_year_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$passing_year = mysqli_real_escape_string($conn, test_input($_REQUEST["passing_year"]));
}
if (empty($_REQUEST["last_school_name"])) {
$errors["last_school_name_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$last_school_name = mysqli_real_escape_string($conn, test_input($_REQUEST["last_school_name"]));
if (preg_match("/^[a-zA-Z0-9 .]*$/", $last_school_name)) {
$errors["last_school_name_error"] = "বাংলায় লিখুন";
}
}
if (empty($_REQUEST["mobile"])) {
$errors["mobile_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$mobile = mysqli_real_escape_string($conn, test_input($_REQUEST["mobile"]));
if (!preg_match("/^[0০]{1}[1১]{1}[0-9০-৯]{9}$/", $mobile)) {
$errors["mobile_error"] = "সঠিক মোবাইল নাম্বার লিখুন";
}
$statement = $conn->prepare('SELECT id FROM students_info WHERE mobile = ? LIMIT 1');
$statement->bind_param('s', $mobile);
$statement->execute();
$mobileCount = $statement->get_result()->num_rows;
if ($mobileCount > 0) {
$errors["mobile_error"] = "ইতি পূর্বে এই মোবাইল নাম্বার ব্যবহৃত হয়েছে";
}
}
$pass = (string) ($_REQUEST["pass"] ?? '');
$cpass = (string) ($_REQUEST["cpass"] ?? '');
if ($pass === '') {
    $errors["pass_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} elseif (strlen($pass) < 5) {
    $errors["pass_error"] = "পাসওয়ার্ড কমপক্ষে পাঁচ অক্ষরের হবে";
}
if ($cpass === '') {
    $errors["cpass_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} elseif ($pass !== $cpass) {
    $errors["cpass_error"] = "পাসওয়ার্ড এবং কনফার্ম পাসওয়ার্ড ম্যাচিং হয়নি";
}


if (empty($_POST["student_pic_data"])) {
    $errors["student_pic_error"] = "শিক্ষার্থীর ছবি আপলোড করুন";
} else {
    $pictureData = (string) $_POST["student_pic_data"];
    if (!preg_match('#^data:image/jpeg;base64,#', $pictureData)) {
        $errors["student_pic_error"] = "ক্রপ করা JPEG ছবি আপলোড করুন";
    }
}

if (empty($errors) == true) {
$statement = $conn->prepare('SELECT id FROM students_info WHERE name_eng = ? AND fname_eng = ? AND mname_eng = ? AND birth_date = ? LIMIT 1');
$statement->bind_param('ssss', $name_eng, $fname_eng, $mname_eng, $birth_date);
$statement->execute();
$stuCount = $statement->get_result()->num_rows;
if ($stuCount > 0) {
$errors["cls_roll_error"] = "ইতি পূর্বে এই শিক্ষার্থীর তথ্য এন্ট্রি হয়েছে";
} else {
$pass_hash = password_hash($pass, PASSWORD_DEFAULT);
try {
    $imageName = saveCroppedJpeg(
        $pictureData,
        'student_pic',
        'student_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.jpg',
        100 * 1024,
        108,
        137
    );
} catch (RuntimeException $exception) {
    $errors["student_pic_error"] = $exception->getMessage();
}
if (empty($errors)) {
try {
$query = preparedInsert($conn, 'students_info', ['admission_token' => $admission_token, 'cls' => $cls, 'sections' => 'others', 'session_year' => $session_year, 'name_eng' => $name_eng, 'name_ban' => $name_ban, 'fname_eng' => $fname_eng, 'fname_ban' => $fname_ban, 'father_nid_no' => $father_nid_no, 'mname_eng' => $mname_eng, 'mname_ban' => $mname_ban, 'mother_nid_no' => $mother_nid_no, 'gender' => $gender, 'religion' => $religion, 'birth_date' => $birth_date, 'birth_reg_no' => $birth_reg_no, 'birth_reg_pic' => '', 'division' => $division, 'district' => $district, 'upazilla' => $upazilla, 'post_office' => $post_office, 'village' => $village, 'house_name' => $house_name, 'last_class' => $last_class, 'exam_roll' => '', 'passing_year' => $passing_year, 'scholarship_stipend_info' => 'not_applicable', 'last_school_name' => $last_school_name, 'mobile' => $mobile, 'student_pic' => $imageName, 'pass' => $pass_hash, 'status' => 0]);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $query = false;
}
if ($query) {




establishAdmissionSession((int) mysqli_insert_id($conn));
$tokenUpdate = $conn->prepare('UPDATE token SET token_status = 1 WHERE token = ?');
$tokenUpdate->bind_param('s', $admission_token);
$query = $tokenUpdate->execute();

header('Location: view_admission_form');
exit;
} else {
    $errors['form_error'] = 'ফর্ম সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।';
}
}
}
}
}
?>
<div class="adm-page">
<?php include("admission_navigation.php");?>
    <section class="adm-hero">
        <div class="container">
            <p class="adm-kicker">অনলাইন ভর্তি</p>
            <h1>শিক্ষার্থীর ভর্তি ফরম</h1>
            <p>জন্ম নিবন্ধন ও জাতীয় পরিচয়পত্র অনুসারে সঠিক তথ্য পূরণ করুন। সম্পন্ন আবেদন পরবর্তীতে লগইন করে দেখা যাবে।</p>
        </div>
    </section>
    <section class="adm-wrap">
        <div class="container" id="reg">
                    <form method="POST" id="admissionForm" class="adm-form needs-validation adm-sheet" action="<?php echo htmlentities($_SERVER['PHP_SELF']); ?>" autocomplete="off" novalidate>
                        <?= csrfField() ?>
                        <?php if (isset($errors["csrf_error"])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($errors["csrf_error"], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (isset($errors["cls_roll_error"])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($errors["cls_roll_error"], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (isset($errors["form_error"])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($errors["form_error"], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <p class="adm-note mb-4">লাল চিহ্নিত <span class="required">*</span> ঘরগুলো অবশ্য পূরণীয়। বাংলা ঘরে বাংলায় এবং ইংরেজি ঘরে ইংরেজিতে লিখুন।</p>
                        <section class="adm-block">
                        <header class="adm-block-head">
                            <span class="adm-step">০১</span>
                            <div>
                                <h2>প্রাথমিক তথ্য</h2>
                                <p>ভর্তি টোকেন, শ্রেণী ও শিক্ষাবর্ষ নির্বাচন করুন।</p>
                            </div>
                        </header>
                        <div class="adm-block-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4 mb-4 mb-md-0 form_field">
                            <label for="admission_token" class="form-label">ভর্তি টোকেন <span
                                        class="required">*</span></label>
                                <input type="text" class="form-control admission_token_input input require <?php
                                if (isset($errors["admission_token_error"])) {
                                echo 'is-invalid';
                                } else if (isset($admission_token)) {
                                if (empty($errors["admission_token_error"])) {
                                echo "is-valid";
                                }
                                } ?>" id="admission_token" name="admission_token" value="<?php if (isset($admission_token)) {
                                echo hText((string) ($admission_token ?? ''));
                                } ?>" placeholder="টোকেনটি লিখুন" autocomplete="off" required>
                                <div class="<?php if (isset($errors["admission_token_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["admission_token_error"])) {
                                    echo $errors["admission_token_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="cls" class="form-label">ভর্তি ইচ্ছুক শ্রেণী <span
                                        class="required">*</span></label>
                                <select class="form-select input require <?php
                                    if (isset($errors["cls_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($cls)) {
                                    if (empty($errors["cls_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="cls" name="cls" required>
                                    <option value="">শ্রেণী নির্বাচন করুন।</option>
                                    <?php
                                    $admissionClassList = admissionClasses(publicDatabase());
                                    if ($admissionClassList === []) {
                                        echo '<option value="">এখন কোন শ্রেণীতে ভর্তি চলছে না</option>';
                                    } else {
                                        echoClassOptions($admissionClassList, $cls ?? '');
                                    }
                                    ?>
                                </select>
                                <div class="<?php if (isset($errors["cls_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["cls_error"])) {
                                    echo $errors["cls_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="session_year" class="form-label">শিক্ষাবর্ষ <span
                                        class="required">*</span></label>
                                <select class="form-select input require <?php
                                    if (isset($errors["session_year_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($session_year)) {
                                    if (empty($errors["session_year_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="session_year" value="<?php if (isset($session_year)) {
                                    echo hText((string) ($session_year ?? ''));
                                    } ?>" name="session_year" required>
                                    <option value="">শিক্ষাবর্ষ নির্বাচন করুন।</option>
                                    <?php echoAdmissionSessionYearOptions($session_year ?? ''); ?>
                                </select>
                                <div class="<?php if (isset($errors["session_year_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["session_year_error"])) {
                                    echo $errors["session_year_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        </div>
                        </section>
                        <section class="adm-block">
                        <header class="adm-block-head">
                            <span class="adm-step">০২</span>
                            <div>
                                <h2>শিক্ষার্থীর ব্যক্তিগত তথ্য</h2>
                                <p>জন্ম নিবন্ধন অনুসারে নাম, জন্ম তারিখ ও ছবি দিন।</p>
                            </div>
                        </header>
                        <div class="adm-block-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6 mb-4 mb-md-0">
                                <label for="name_ban" class="form-label">নাম (বাংলায়) <span
                                        class="required">*</span></label>
                                <input type="text" name="name_ban" class="form-control input bengali_input require <?php
                                if (isset($errors["name_ban_error"])) {
                                echo 'is-invalid';
                                } else if (isset($name_ban)) {
                                if (empty($errors["name_ban_error"])) {
                                echo "is-valid";
                                }
                                } ?>" value="<?php if (isset($name_ban)) {
                                echo hText((string) ($name_ban ?? ''));
                                } ?>" id="name_ban" placeholder="বাংলায় পুরো নাম লিখুন" required>
                                <div class="<?php if (isset($errors["name_ban_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["name_ban_error"])) {
                                    echo $errors["name_ban_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="name_eng" class="form-label">নাম (ইংরেজিতে) <span
                                        class="required">*</span></label>
                                <input type="text" autocapitalize="sentences" name="name_eng" class="form-control input english_input require <?php
                                if (isset($errors["name_eng_error"])) {
                                echo 'is-invalid';
                                } else if (isset($name_eng)) {
                                if (empty($errors["name_eng_error"])) {
                                echo "is-valid";
                                }
                                } ?>" value="<?php if (isset($name_eng)) {
                                echo hText((string) ($name_eng ?? ''));
                                } ?>" id="name_eng" placeholder="ইংরেজিতে পুরো নাম লিখুন" required>
                                <div class="<?php if (isset($errors["name_eng_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["name_eng_error"])) {
                                    echo $errors["name_eng_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label for="gender" class="form-label">লিঙ্গ <span class="required">*</span></label>
                                <select class="form-select input require <?php
                                    if (isset($errors["gender_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($gender)) {
                                    if (empty($errors["gender_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="gender" name="gender" required>
                                    <option value="">জেন্ডার নির্বাচন করুন</option>
                                    <option value="male" <?php if (isset($gender)) {
                                        if ($gender == "male") {
                                        echo "selected";
                                        }
                                    } ?>>ছাত্র</option>
                                    <option value="female" <?php if (isset($gender)) {
                                        if ($gender == "female") {
                                        echo "selected";
                                        }
                                    } ?>>ছাত্রী</option>
                                </select>
                                <div class="<?php if (isset($errors["gender_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["gender_error"])) {
                                    echo $errors["gender_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="religion" class="form-label">ধর্ম <span class="required">*</span></label>
                                <select class="form-select input require <?php
                                    if (isset($errors["religion_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($religion)) {
                                    if (empty($errors["religion_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="religion" name="religion" value="<?php if (isset($religion)) {
                                    echo hText((string) ($religion ?? ''));
                                    } ?>" required>
                                    <option value="">ধর্ম নির্বাচন করুন</option>
                                    <option value="Islam" <?php if (isset($religion)) {
                                        if ($religion == "Islam") {
                                        echo "selected";
                                        }
                                    } ?>>ইসলাম</option>
                                    <option value="Hindu" <?php if (isset($religion)) {
                                        if ($religion == "Hindu") {
                                        echo "selected";
                                        }
                                    } ?>>হিন্দু</option>
                                    <option value="Buddhist" <?php if (isset($religion)) {
                                        if ($religion == "Buddhist") {
                                        echo "selected";
                                        }
                                    } ?>>বৌদ্ধ</option>
                                    <option value="Christian" <?php if (isset($religion)) {
                                        if ($religion == "Christian") {
                                        echo "selected";
                                        }
                                    } ?>>খ্রিস্টান</option>
                                </select>
                                <div class="<?php if (isset($errors["religion_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["religion_error"])) {
                                    echo $errors["religion_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="birth_date" class="form-label">জন্ম তারিখ <span
                                        class="required">*</span></label>
                                <input type="date" class="form-control input require <?php
                                if (isset($errors["birth_date_error"])) {
                                echo 'is-invalid';
                                } else if (isset($birth_date)) {
                                if (empty($errors["birth_date_error"])) {
                                echo "is-valid";
                                }
                                } ?>" id="birth_date" name="birth_date" value="<?php if (isset($birth_date)) {
                                echo hText((string) ($birth_date ?? ''));
                                } ?>" placeholder="তারিখ নির্বাচন করুন" required>
                                <div class="<?php if (isset($errors["birth_date_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["birth_date_error"])) {
                                    echo $errors["birth_date_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="birth_reg_no" class="form-label">জন্ম নিবন্ধন নম্বর <span
                                        class="required">*</span></label>
                                <input type="text" class="form-control birth_reg_no_input input require <?php
                                if (isset($errors["birth_reg_no_error"])) {
                                echo 'is-invalid';
                                } else if (isset($birth_reg_no)) {
                                if (empty($errors["birth_reg_no_error"])) {
                                echo "is-valid";
                                }
                                } ?>" id="birth_reg_no" name="birth_reg_no" maxlength="17" value="<?php if (isset($birth_reg_no)) {
                                echo hText((string) ($birth_reg_no ?? ''));
                                } ?>" placeholder="শিক্ষার্থীর জন্ম নিবন্ধন নম্বর লিখুন" required>
                                <div class="<?php if (isset($errors["birth_reg_no_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["birth_reg_no_error"])) {
                                    echo $errors["birth_reg_no_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="adm-photo mb-3">
                            <div class="adm-photo-frame" aria-hidden="true">পাসপোর্ট<br>আকার</div>
                            <div>
                            <label class="form-label" for="studentPicture">শিক্ষার্থীর ছবি <span class="required">*</span></label>
                            <input class="form-control <?= isset($errors['student_pic_error']) ? 'is-invalid' : '' ?>" type="file" id="studentPicture" accept="image/jpeg,image/png,image/webp">
                            <input type="hidden" name="student_pic_data" id="studentPicData" value="<?= isset($pictureData) ? h($pictureData) : '' ?>">
                            <small class="text-secondary d-block mt-1">১০৮×১৩৭ পিক্সেল JPEG, সর্বোচ্চ ১০০KB। ছবি নির্বাচন করে ক্রপ করুন।</small>
                            <?php if (isset($errors['student_pic_error'])): ?>
                                <div class="invalid-feedback d-block"><?= h($errors['student_pic_error']) ?></div>
                            <?php endif; ?>
                            <div id="studentCropPreview" class="adm-photo-crop mt-2" style="<?= empty($pictureData) ? 'display:none' : '' ?>">
                                <img id="studentCropImage" alt="ছবির প্রিভিউ"<?= !empty($pictureData) ? ' src="' . h($pictureData) . '"' : '' ?>>
                            </div>
                            </div>
                        </div>
                        </div>
                        </section>
                        <section class="adm-block">
                        <header class="adm-block-head">
                            <span class="adm-step">০৩</span>
                            <div>
                                <h2>পিতা ও মাতার তথ্য</h2>
                                <p>জাতীয় পরিচয়পত্র অনুসারে পূরণ করুন। NID ঐচ্ছিক।</p>
                            </div>
                        </header>
                        <div class="adm-block-body">
                        <div class="row g-3 my-1">
                            <div class="col-md-6">
                                <div class="adm-subcard">
                                <h3>পিতার তথ্য</h3>
                                <div class="mb-4">
                                    <label for="fname_ban" class="form-label">পিতার নাম (বাংলায়) <span
                                            class="required">*</span></label>
                                    <input type="text" name="fname_ban" value="<?php if (isset($fname_ban)) {
                                    echo hText((string) ($fname_ban ?? ''));
                                    } ?>" class="form-control input bengali_input require <?php
                                    if (isset($errors["fname_ban_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($fname_ban)) {
                                    if (empty($errors["fname_ban_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="fname_ban" placeholder="বাংলায় পিতার নাম লিখুন" required>
                                    <div class="<?php if (isset($errors["fname_ban_error"])) {
                                        echo 'invalid-feedback';
                                        } ?>">
                                        <?php
                                        if (isset($errors["fname_ban_error"])) {
                                        echo $errors["fname_ban_error"];
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label for="fname_eng" class="form-label">পিতার নাম (ইংরেজিতে) <span
                                            class="required">*</span></label>
                                    <input type="text" name="fname_eng" value="<?php if (isset($fname_eng)) {
                                    echo hText((string) ($fname_eng ?? ''));
                                    } ?>" class="form-control input english_input require <?php
                                    if (isset($errors["fname_eng_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($fname_eng)) {
                                    if (empty($errors["fname_eng_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="fname_eng" placeholder="ইংরেজিতে পিতার নাম লিখুন" required>
                                    <div class="<?php if (isset($errors["fname_eng_error"])) {
                                        echo 'invalid-feedback';
                                        } ?>">
                                        <?php
                                        if (isset($errors["fname_eng_error"])) {
                                        echo $errors["fname_eng_error"];
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label for="father_nid_no">পিতার জাতীয় পরিচয়পত্র নম্বর</label>
                                    <input type="text" name="father_nid_no" value="<?php if (isset($father_nid_no)) {
                                    echo hText((string) ($father_nid_no ?? ''));
                                    } ?>" class="form-control input nid_no_input nonrequire
                                    <?php
                                    if (isset($errors["father_nid_no_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($_POST['father_nid_no'])) {
                                    if (empty($errors["father_nid_no_error"])) {
                                    echo "is-valid";
                                    }
                                    }
                                    ?>" id="father_nid_no" maxlength="17"
                                        placeholder="পিতার জাতীয় পরিচয়পত্র নম্বর লিখুন">
                                    <div class="<?php if (isset($errors["father_nid_no_error"])) {
                                        echo 'invalid-feedback';
                                        } ?>">
                                        <?php
                                        if (isset($errors["father_nid_no_error"])) {
                                        echo $errors["father_nid_no_error"];
                                        }
                                        ?>
                                    </div>
                                </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="adm-subcard">
                                <h3>মাতার তথ্য</h3>
                                <div class="mb-4">
                                    <label for="mname_ban" class="form-label">মাতার নাম (বাংলায়) <span
                                            class="required">*</span></label>
                                    <input type="text" name="mname_ban" value="<?php if (isset($mname_ban)) {
                                    echo hText((string) ($mname_ban ?? ''));
                                    } ?>" class="form-control input bengali_input require <?php
                                    if (isset($errors["mname_ban_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($mname_ban)) {
                                    if (empty($errors["mname_ban_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="mname_ban" placeholder="বাংলায় মাতার নাম লিখুন" required>
                                    <div class="<?php if (isset($errors["mname_ban_error"])) {
                                        echo 'invalid-feedback';
                                        } ?>">
                                        <?php
                                        if (isset($errors["mname_ban_error"])) {
                                        echo $errors["mname_ban_error"];
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label for="mname_eng" class="form-label">মাতার নাম (ইংরেজিতে) <span
                                            class="required">*</span></label>
                                    <input type="text" name="mname_eng" value="<?php if (isset($mname_eng)) {
                                    echo hText((string) ($mname_eng ?? ''));
                                    } ?>" class="form-control input english_input require <?php
                                    if (isset($errors["mname_eng_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($mname_eng)) {
                                    if (empty($errors["mname_eng_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="mname_eng" placeholder="ইংরেজিতে মাতার নাম লিখুন" required>
                                    <div class="<?php if (isset($errors["mname_eng_error"])) {
                                        echo 'invalid-feedback';
                                        } ?>">
                                        <?php
                                        if (isset($errors["mname_eng_error"])) {
                                        echo $errors["mname_eng_error"];
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label for="mother_nid_no">মাতার জাতীয় পরিচয়পত্র নম্বর </label>
                                    <input type="text" name="mother_nid_no" value="<?php if (isset($mother_nid_no)) {
                                    echo hText((string) ($mother_nid_no ?? ''));
                                    } ?>" class="form-control input nid_no_input nonrequire
                                    
                                    <?php
                                    if (isset($errors["mother_nid_no_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($_POST['mother_nid_no'])) {
                                    if (empty($errors["mother_nid_no_error"])) {
                                    echo "is-valid";
                                    }
                                    }
                                    ?>
                                    " id="mother_nid_no" maxlength="17"
                                        placeholder="মাতার  জাতীয় পরিচয়পত্র নম্বর লিখুন">
                                    <div class="<?php if (isset($errors["mother_nid_no_error"])) {
                                        echo 'invalid-feedback';
                                        } ?>">
                                        <?php
                                        if (isset($errors["mother_nid_no_error"])) {
                                        echo $errors["mother_nid_no_error"];
                                        }
                                        ?>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </div>
                        </div>
                        </section>
                        <section class="adm-block">
                        <header class="adm-block-head">
                            <span class="adm-step">০৪</span>
                            <div>
                                <h2>পূর্বের বিদ্যালয়ের তথ্য</h2>
                                <p>অধ্যয়নকৃত শ্রেণী, শিক্ষাবর্ষ ও বিদ্যালয়ের নাম লিখুন।</p>
                            </div>
                        </header>
                        <div class="adm-block-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label for="last_class" class="form-label">অধ্যয়নকৃত শ্রেণী <span
                                        class="required">*</span></label>
                                <input type="text" name="last_class" class="form-control input bengali_input require <?php
                                    if (isset($errors["last_class_error"])) {
                                    echo 'is-invalid';
                                    } else if ($last_class !== '') {
                                    if (empty($errors["last_class_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" value="<?php echo hText((string) ($last_class ?? '')); ?>" id="last_class" placeholder="যেমন: ৫ম / ৮ম" required>
                                <div class="<?php if (isset($errors["last_class_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["last_class_error"])) {
                                    echo $errors["last_class_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="passing_year" class="form-label">শিক্ষাবর্ষ <span
                                        class="required">*</span></label>
                                <select class="form-select input require <?php
                                    if (isset($errors["passing_year_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($passing_year)) {
                                    if (empty($errors["passing_year_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="passing_year" name="passing_year" required>
                                    <option value="">শিক্ষাবর্ষ নির্বাচন করুন</option>
                                    <?php echoPreviousSchoolYearOptions($passing_year ?? ''); ?>
                                </select>
                                <div class="<?php if (isset($errors["passing_year_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["passing_year_error"])) {
                                    echo $errors["passing_year_error"];
                                    }
                                    ?>
                                </div>
                            </div>

                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label for="last_school_name" class="form-label">অধ্যয়নকৃত বিদ্যালয়ের নাম <span
                                        class="required">*</span></label>
                                <input type="text" class="form-control input bengali_input require <?php
                                if (isset($errors["last_school_name_error"])) {
                                echo 'is-invalid';
                                } else if (isset($last_school_name)) {
                                if (empty($errors["last_school_name_error"])) {
                                echo "is-valid";
                                }
                                } ?>" id="last_school_name" name="last_school_name" value="<?php if (isset($last_school_name)) {
                                echo hText((string) ($last_school_name ?? ''));
                                } ?>" placeholder="অধ্যয়নকৃত বিদ্যালয়ের নাম লিখুন" required>
                                <div class="<?php if (isset($errors["last_school_name_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["last_school_name_error"])) {
                                    echo $errors["last_school_name_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        </div>
                        </section>
                        <section class="adm-block">
                        <header class="adm-block-head">
                            <span class="adm-step">০৫</span>
                            <div>
                                <h2>পূর্ণাঙ্গ ঠিকানা</h2>
                                <p>বিভাগ থেকে উপজেলা নির্বাচন করলে গ্রাম ও ডাকঘর লেখা যাবে।</p>
                            </div>
                        </header>
                        <div class="adm-block-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4 mb-4 mb-md-0">
                                <label for="division" class="form-label">বিভাগ <span class="required">*</span></label>
                                <select class="form-select input require <?php
                                    if (isset($errors["division_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($division)) {
                                    if (empty($errors["division_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="division" name="division" required>
                                    <option selected value="">বিভাগ নির্বাচন করুন</option>
                                    <?php
                                    $sql = "SELECT * FROM divisions";
                                    $query = mysqli_query($conn, $sql);
                                    if ($query && mysqli_num_rows($query) > 0) {
                                    while ($row = mysqli_fetch_assoc($query)) { ?>
                                    <option value="<?php echo (int) $row['id'] ?>" <?php if (isset($division)) {
                                        if ($division == $row['id']) {
                                        echo "selected";
                                        }
                                        } ?>>
                                        <?php echo htmlspecialchars((string) $row['bn_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                    <?php  }
                                    }
                                    ?>
                                </select>
                                <div class="<?php if (isset($errors["division_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["division_error"])) {
                                    echo $errors["division_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-4 mb-4 mb-md-0">
                                <label for="district" class="form-label">জেলা <span class="required">*</span></label>
                                <select class="form-select input require <?php
                                    if (isset($errors["district_error"])) {
                                    echo 'is-invalid';
                                    } else if ($district !== '') {
                                    if (empty($errors["district_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="district" name="district" readonly required>
                                    <?php
                                    if ($district !== '' && (int) $district > 0) {
                                    $districtId = (int) $district;
                                    $sql = "SELECT * FROM districts WHERE id = $districtId";
                                    $query = mysqli_query($conn, $sql);
                                    if ($query && mysqli_num_rows($query) > 0) {
                                    while ($row = mysqli_fetch_assoc($query)) {
                                    echo '<option value="' . (int) $district . '">' . htmlspecialchars((string) $row['bn_name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                    }
                                    }
                                    ?>
                                </select>
                                <div class="<?php if (isset($errors["district_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["district_error"])) {
                                    echo $errors["district_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-4 mb-4 mb-md-0">
                                <label for="upazilla" class="form-label">উপজেলা <span class="required">*</span></label>
                                <select class="form-select input require <?php
                                    if (isset($errors["upazilla_error"])) {
                                    echo 'is-invalid';
                                    } else if ($upazilla !== '') {
                                    if (empty($errors["upazilla_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="upazilla" name="upazilla" value="<?php if (isset($upazilla)) {
                                    echo hText((string) ($upazilla ?? ''));
                                    } ?>" readonly required>
                                    <?php
                                    if ($upazilla !== '' && (int) $upazilla > 0) {
                                    $upazillaId = (int) $upazilla;
                                    $sql = "SELECT * FROM upazilas WHERE id = $upazillaId";
                                    $query = mysqli_query($conn, $sql);
                                    if ($query && mysqli_num_rows($query) > 0) {
                                    while ($row = mysqli_fetch_assoc($query)) {
                                    echo '<option value="' . (int) $upazilla . '">' . htmlspecialchars((string) $row['bn_name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                    }
                                    }
                                    ?>
                                </select>
                                <div class="<?php if (isset($errors["upazilla_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["upazilla_error"])) {
                                    echo $errors["upazilla_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-4 mb-4 mb-md-0">
                                <label for="post_office" class="form-label">ডাকঘর <span
                                        class="required">*</span></label>
                                <input class="form-control input bengali_input require <?php
                                if (isset($errors["post_office_error"])) {
                                echo 'is-invalid';
                                } else if (isset($post_office)) {
                                if (empty($errors["post_office_error"])) {
                                echo "is-valid";
                                }
                                } ?>" id="post_office" name="post_office" value="<?php if (isset($post_office)) {
                                echo hText((string) ($post_office ?? ''));
                                } ?>" placeholder="ডাকঘরের নাম লিখুন" <?= empty($upazilla) ? 'readonly' : '' ?> required>
                                <div class="<?php if (isset($errors["post_office_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["post_office_error"])) {
                                    echo $errors["post_office_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-4 mb-4 mb-md-0">
                                <label for="village" class="form-label">গ্রাম <span class="required">*</span></label>
                                <input class="form-control input bengali_input require <?php
                                if (isset($errors["village_error"])) {
                                echo 'is-invalid';
                                } else if (isset($village)) {
                                if (empty($errors["village_error"])) {
                                echo "is-valid";
                                }
                                } ?>" id="village" name="village" value="<?php if (isset($village)) {
                                echo hText((string) ($village ?? ''));
                                } ?>" placeholder="গ্রামের নাম লিখুন" <?= empty($upazilla) ? 'readonly' : '' ?> required>
                                <div class="<?php if (isset($errors["village_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["village_error"])) {
                                    echo $errors["village_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-4 mb-4 mb-md-0">
                                <label for="house_name" class="form-label">বাড়ী/বাসার নাম <span
                                        class="required">*</span></label>
                                <input class="form-control input bengali_input require <?php
                                if (isset($errors["house_name_error"])) {
                                echo 'is-invalid';
                                } else if (isset($house_name)) {
                                if (empty($errors["house_name_error"])) {
                                echo "is-valid";
                                }
                                } ?>" id="house_name" name="house_name" value="<?php if (isset($house_name)) {
                                echo hText((string) ($house_name ?? ''));
                                } ?>" placeholder="বাড়ী/বাসার নাম লিখুন" <?= empty($upazilla) ? 'readonly' : '' ?> required>
                                <div class="<?php if (isset($errors["house_name_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["house_name_error"])) {
                                    echo $errors["house_name_error"];
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        </div>
                        </section>
                        <section class="adm-block">
                        <header class="adm-block-head">
                            <span class="adm-step">০৬</span>
                            <div>
                                <h2>লগইন তথ্য</h2>
                                <p>মোবাইল নম্বর ও পাসওয়ার্ড পরবর্তীতে আবেদন দেখতে ব্যবহৃত হবে।</p>
                            </div>
                        </header>
                        <div class="adm-block-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label for="mobile" class="form-label">মোবাইল নম্বর <span class="required">*</span></label>
                                <input type="tel" name="mobile" class="form-control mobile_input input require <?php
                                                    if (isset($errors["mobile_error"])) {
                                                    echo 'is-invalid';
                                                    } else if (isset($mobile)) {
                                                    if (empty($errors["mobile_error"])) {
                                                    echo "is-valid";
                                                    }
                                                    } ?>" value="<?php if (isset($mobile)) {
                                                    echo hText((string) ($mobile ?? ''));
                                                    } ?>" id="mobile" pattern="[0-9]{11}"
                                                        title="১১ সংখ্যার মোবাইল নম্বর লিখুন" maxlength="11"
                                                        placeholder="০১XXXXXXXXX" required>
                                                    <div class="<?php if (isset($errors["mobile_error"])) {
                                                        echo 'invalid-feedback';
                                                        } ?>">
                                                        <?php
                                                        if (isset($errors["mobile_error"])) {
                                                        echo $errors["mobile_error"];
                                                        }
                                                        ?>
                                                    </div>
                            </div>
                            <div class="col-md-4">
                                <label for="pass" class="form-label">পাসওয়ার্ড <span class="required">*</span></label>
                                <input name="pass"
                                                        placeholder="কমপক্ষে ৫ অক্ষর"
                                                        type="password" autocomplete="new-password" class="form-control pass_input input require <?php
                                                    if (isset($errors["pass_error"])) {
                                                    echo 'is-invalid';
                                                    } ?>" aria-invalid="false" minlength="5" required
                                                        id="pass">
                                                    <div class="<?php if (isset($errors["pass_error"])) {
                                                        echo 'invalid-feedback';
                                                        } ?>">
                                                        <?php
                                                        if (isset($errors["pass_error"])) {
                                                        echo $errors["pass_error"];
                                                        }
                                                        ?>
                                                    </div>
                            </div>
                            <div class="col-md-4">
                                <label for="cpass" class="form-label">পাসওয়ার্ড নিশ্চিত করুন <span class="required">*</span></label>
                                <input name="cpass" placeholder="একই পাসওয়ার্ড আবার লিখুন"
                                                        type="password" autocomplete="new-password" class="form-control cpass_input input require <?php
                                                    if (isset($errors["cpass_error"])) {
                                                    echo 'is-invalid';
                                                    } ?>" id="cpass" aria-invalid="false" minlength="5"
                                                        required>
                                                    <div class="<?php if (isset($errors["cpass_error"])) {
                                                        echo 'invalid-feedback';
                                                        } ?>">
                                                        <?php
                                                        if (isset($errors["cpass_error"])) {
                                                        echo $errors["cpass_error"];
                                                        }
                                                        ?>
                                                    </div>
                            </div>
                        </div>
                        </div>
                        </section>
                        <div class="adm-submit">
                        <div class="form-check mb-3">
                            <input type="checkbox" name="rules" value="agree" class="form-check-input is-invalid"
                                id="rules" required>
                            <label class="form-check-label" for="rules">প্রতিষ্ঠানের সমস্ত শর্তাবলী এবং
                                প্রতিশ্রুতি মেনে চলব
                                <a href="terms_conditions" target="_blank">শর্তাবলী দেখুন</a>
                            </label>
                            <div class="invalid-feedback">
                                জমা দেওয়ার আগে আপনাকে অবশ্যই সমস্ত শর্তাবলীর সাথে একমত হতে হবে।
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="submit" id="admission_submit" name="admission_submit"
                                class="btn btn-sweet px-5" disabled>আবেদন জমা দিন</button>
                        </div>
                        </div>
                    </form>
        </div>
    </section>
</div>

                        <!-- history close -->
<?php $keepDocumentOpen = true; include('footer.php'); ?>
<script>
$(document).ready(function() {
    function loadData(type, category_id) {
        if (type !== undefined) {
            $.ajax({
                url: "selectmenu.php",
                type: "POST",
                data: {
                    type: type,
                    id: category_id
                },
                success: function(data) {
                    // if (type == "" || type == null) {
                    //     $("#division").html(data);
                    // } else
                    if (type == "district") {
                        $("#district").html(data);
                    } else if (type == "upazilla") {
                        $("#upazilla").html(data);
                    }
                }
            })
        }
    }
    loadData();
    $("#division").on("change", function() {
        var division = $("#division").val();
        if (division != "") {
            $("#district").html("").removeAttr('readonly');
            loadData("district", division);
        } else {
            $("#district").html("").attr('readonly', 'true');
        }
        $("#upazilla").html("").attr('readonly', 'true');
        $("#village").val("").attr('readonly', 'true');
        $("#post_office").val("").attr('readonly', 'true');
        $("#house_name").val("").attr('readonly', 'true');
    });
    $("#district").on("change", function() {
        var district = $("#district").val();
        if (district != "") {
            $("#upazilla").html("").removeAttr('readonly');
            loadData("upazilla", district);
        } else {
            $("#upazilla").html("").attr('readonly', 'true');
        }
        $("#village").val("").attr('readonly', 'true');
        $("#post_office").val("").attr('readonly', 'true');
        $("#house_name").val("").attr('readonly', 'true');
    });
    $("#upazilla").on("change", function() {
        var upazilla = $("#upazilla").val();
        if (upazilla != "") {
            $("#village").val("").removeAttr('readonly');
            $("#post_office").val("").removeAttr("readonly");
            $("#house_name").val("").removeAttr("readonly");
        } else {
            $("#village").val("").attr('readonly', 'true');
            $("#post_office").val("").attr('readonly', 'true');
            $("#house_name").val("").attr('readonly', 'true');
        }
    });
})
</script>
<script src="js/admission-validation.js?v=<?= (int) @filemtime(__DIR__ . '/js/admission-validation.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
<script>
(() => {
  const form = document.getElementById('admissionForm');
  const pictureInput = document.getElementById('studentPicture');
  const pictureData = document.getElementById('studentPicData');
  const cropImage = document.getElementById('studentCropImage');
  const cropPreview = document.getElementById('studentCropPreview');
  if (!form || !pictureInput || !pictureData || !cropImage || !cropPreview) return;
  let cropper = null;
  pictureInput.addEventListener('change', () => {
    const file = pictureInput.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
      pictureInput.classList.remove('is-valid');
      pictureInput.classList.add('is-invalid');
      pictureInput.setCustomValidity('একটি ছবি নির্বাচন করুন।');
      return;
    }
    pictureInput.setCustomValidity('');
    pictureInput.classList.remove('is-invalid');
    pictureInput.classList.add('is-valid');
    const reader = new FileReader();
    reader.onload = () => {
      cropImage.src = reader.result;
      cropPreview.style.display = 'block';
      if (cropper) cropper.destroy();
      cropper = new Cropper(cropImage, { aspectRatio: 108 / 137, viewMode: 1, autoCropArea: 1 });
    };
    reader.readAsDataURL(file);
  });
  const compressedCrop = (sourceCanvas, maxWidth, maxHeight, maxLength) => {
    let width = sourceCanvas.width;
    let height = sourceCanvas.height;
    const scale = Math.min(1, maxWidth / width, maxHeight / height);
    width = Math.max(1, Math.round(width * scale));
    height = Math.max(1, Math.round(height * scale));
    const canvas = document.createElement('canvas');
    let dataUrl = '';
    for (let attempt = 0; attempt < 8; attempt += 1) {
      canvas.width = width;
      canvas.height = height;
      canvas.getContext('2d').drawImage(sourceCanvas, 0, 0, width, height);
      let quality = 0.9;
      dataUrl = canvas.toDataURL('image/jpeg', quality);
      while (dataUrl.length > maxLength && quality > 0.45) {
        quality -= 0.05;
        dataUrl = canvas.toDataURL('image/jpeg', quality);
      }
      if (dataUrl.length <= maxLength) return dataUrl;
      width = Math.max(1, Math.round(width * 0.8));
      height = Math.max(1, Math.round(height * 0.8));
    }
    return dataUrl;
  };
  form.addEventListener('submit', (event) => {
    pictureInput.setCustomValidity('');
    if (cropper) {
      const canvas = cropper.getCroppedCanvas({ width: 108, height: 137, imageSmoothingQuality: 'high' });
      if (!canvas) {
        event.preventDefault();
        pictureInput.classList.add('is-invalid');
        pictureInput.setCustomValidity('ছবি ক্রপ করুন।');
        return;
      }
      const data = compressedCrop(canvas, 108, 137, 136500);
      if (data.length > 136500) {
        event.preventDefault();
        pictureInput.classList.add('is-invalid');
        pictureInput.setCustomValidity('ক্রপ করা ছবি ১০০KB এর নিচে হতে হবে।');
        return;
      }
      pictureData.value = data;
      pictureInput.classList.remove('is-invalid');
      pictureInput.classList.add('is-valid');
    } else if (!pictureData.value) {
      event.preventDefault();
      pictureInput.classList.add('is-invalid');
      pictureInput.setCustomValidity('শিক্ষার্থীর ছবি আপলোড করুন');
    }
  });
})();
</script>
</body>
</html>
