<?php
$pageAssets = ['jquery'];
include('header.php');
include('navbar&marqueetag.php');
?>
<?php if (!isset($_SESSION['admission_login_id'])) {
header("Location: admission_login");
exit();
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">

<style>
    select[readonly] option, select[readonly] optgroup {
    display: none;
}
/* Sweet Mini Section CSS */
.sweet-mini-section {
    height: 280px;
    background-position-x: right;
    background-position-y: center;
    background-size: cover;
    background-repeat: no-repeat;
    background-color: var(--sweet-header-bg);
}

.bg-mini-overlay {
    width: 100%;
    height: 100%;
    background-color: var(--sweet-header-bg);
}

.sweet-mini-section h2 {
    font-size: 38px;
    font-weight: 600;
    color: var(--sweet-header-color);
    text-align: left;
    margin-bottom: 0px;
    text-shadow: 0 0 5px #000;
}

/* sweet button */
.btn-sweet {
    color: #fff;
    background-color: var(--sweet-btn);
    border-color: var(--sweet-btn);
}

.btn-sweet:hover {
    color: #fff;
    background-color: var(--sweet-btn-hover);
    border-color: var(--sweet-btn-hover);
}

/* Reg Form Image */
#uploaded_image img {
    width: 100px;
    height: 100px;
    border: 1px solid #eaecec;
    border-radius: 50%;
    padding: 3px;
}

.pUploader label {
    height: 100px;
    border: 1px dashed #959595;
    padding: 1rem;
    outline: none;
    cursor: pointer;
    border-radius: .25rem;
}

.content-title {
    font-size: 22px;
    font-weight: 600;
    color: var(--heading-color);
    margin-bottom: 23px;
    border-left: 5px solid var(--primary-color);
    padding-left: 10px;
}

.required {
    color: #E41C26;
}

/* reg-area */
.reg-area {
    justify-content: center;
    min-height: 80vh;
    background-color: rgba(0, 0, 0, 0);
    background-size: auto;
    background-color: var(--section-bg);
    background-size: cover;
}

.reg-title {
    font-size: 20px;
    color: #FFF;
    background: linear-gradient(-45deg, #130947, #23d5ab);
    background-size: 400% 400%;
    animation: gradient 30s ease-in infinite;
 
    padding: 10px 12px;
    border-radius: 5px !important;
    margin-top: 15px;
    margin-bottom: 10px;
}

label {
    color: var(--heading-color);
    font-weight: 500;
    margin-bottom: 7px !important;
    font-size: 17px;
}

.form-control,
.form-select,
.dd-handle {
    color: #324148;
    border-width: 1px;
    border-color: #eaecec;
    padding: 12px 12px;
    border-radius: 8px;
    font-size: 16px;
}

input[type="text"]:focus,
input[type="password"]:focus,
input[type="datetime"]:focus,
input[type="datetime-local"]:focus,
input[type="date"]:focus,
input[type="month"]:focus,
input[type="time"]:focus,
input[type="week"]:focus,
input[type="number"]:focus,
input[type="email"]:focus,
input[type="url"]:focus,
input[type="search"]:focus,
input[type="tel"]:focus,
input[type="color"]:focus,
.uneditable-input:focus {
    border-color: rgba(33, 84, 172, 0.8);
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.075) inset, 0 0 8px rgba(126, 239, 104, 0.6);
    outline: 0 none;
}

.reg_section {
    height: 140px !important;
}

:root {
    --primary-color: #11116d;
    --heading-color: #130947;
    --text-color: #4f4f4f;
    --site-bg: #ffffff;
    --section-bg: #f2f2f2;
    --heading-hr-color: #11116d;
    --sweet-heading-color: #130947;
    --sweet-header-bg: rgba(17, 17, 109, 0.4);
    --sweet-header-color: #FFF;
    --sweet-btn: #11116d;
    --sweet-btn-hover: #11116dEF;
    --header-bg: #11116d;
}

.sweet-mini-section {
    background: linear-gradient(-45deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
    background-size: 400% 400%;
    animation: gradient 5s ease infinite;
    height: 100vh;
}

@keyframes gradient {
    0% {
        background-position: 0% 50%;
    }

    50% {
        background-position: 100% 50%;
    }

    100% {
        background-position: 0% 50%;
    }
}
</style>
<?php
$_POST['update_id'] = (int) $_SESSION['admission_login_id'];
$errors = array();
$pictureData = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_REQUEST['admission_form_edit']) && !verifyCsrfToken()) {
    $errors["csrf_error"] = "Invalid security token. Please try again.";
} elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_REQUEST['admission_form_edit'])) {

if (isset($_REQUEST["cls_roll_status"])) {
    $cls_roll_status = mysqli_real_escape_string($conn, test_input($_REQUEST["cls_roll_status"]));
    }
if (isset($_REQUEST["sections_status"])) {
    $sections_status = mysqli_real_escape_string($conn, test_input($_REQUEST["sections_status"]));
    }
if (isset($_REQUEST["admission_token"])) {
    $admission_token = mysqli_real_escape_string($conn, test_input($_REQUEST["admission_token"]));
    }
if (empty($_REQUEST["cls"])) {
$errors["cls_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$cls = mysqli_real_escape_string($conn, test_input($_REQUEST["cls"]));
}

if (isset($_REQUEST["sections"])) {
    $sections = mysqli_real_escape_string($conn, test_input($_REQUEST["sections"]));
    }else{
        $sections = 'others';
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
$father_nid_no = '';
$mother_nid_no = '';
if (!empty($_REQUEST["father_nid_no"])) {
$father_nid_no = mysqli_real_escape_string($conn, test_input($_REQUEST["father_nid_no"]));
if ((strlen($father_nid_no) != "10") && (strlen($father_nid_no) != "13") && (strlen($father_nid_no) != "17")) {
$errors["father_nid_no_error"] = "জাতীয় পরিচয় পত্র নাম্বার 10/13/17 সংখ্যার হয়। আপনি লিখেছেন " . strlen($father_nid_no) . " সংখ্যা";
}
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
if (!empty($_REQUEST["mother_nid_no"])) {
$mother_nid_no = mysqli_real_escape_string($conn, test_input($_REQUEST["mother_nid_no"]));
if ((strlen($mother_nid_no) != "10") && (strlen($mother_nid_no) != "13") && (strlen($mother_nid_no) != "17")) {
$errors["mother_nid_no_error"] = "জাতীয় পরিচয় পত্র নাম্বার 10/13/17 সংখ্যার হয়। আপনি লিখেছেন " . strlen($mother_nid_no) . " সংখ্যা";
}
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
$update_id = (int) $_SESSION['admission_login_id'];
$statement = $conn->prepare('SELECT id FROM students_info WHERE birth_reg_no = ? AND id <> ? LIMIT 1');
$statement->bind_param('si', $birth_reg_no, $update_id);
$statement->execute();
$rowCount = $statement->get_result()->num_rows;
if ($rowCount > 0) {
$errors["birth_reg_no_error"] = "ইতি পূর্বে এই জন্ম নিবন্ধন নাম্বার ব্যবহৃত হয়েছে";
}
}
if (empty($_REQUEST["division"])) {
$errors["division_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$division = mysqli_real_escape_string($conn, test_input($_REQUEST["division"]));
}
if (empty($_REQUEST["district"])) {
$errors["district_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$district = (string) (int) $_REQUEST["district"];
}
if (empty($_REQUEST["upazilla"])) {
$errors["upazilla_error"] = "এই ফিল্ডটি অবশ্য পূরণীয়";
} else {
$upazilla = (string) (int) $_REQUEST["upazilla"];
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


if (empty($errors) == true) {
    
$statement = $conn->prepare('SELECT id FROM students_info WHERE name_eng = ? AND fname_eng = ? AND mname_eng = ? AND birth_date = ? AND id <> ? LIMIT 1');
$statement->bind_param('ssssi', $name_eng, $fname_eng, $mname_eng, $birth_date, $update_id);
$statement->execute();
$stuCount = $statement->get_result()->num_rows;
if ($stuCount > 0) {
$errors["cls_error"] = "ইতি পূর্বে এই শিক্ষার্থীর তথ্য এন্ট্রি হয়েছে";
} else {
// $birth_reg_pic_new_name = "birth_reg_pic_" . $cls . "_" . $session_year . "_" . $mobile . "." . $birth_reg_pic_extension;
// $student_pic_new_name = "student_pic_" . $cls . "_" . $session_year . "_" . $mobile . "." . $student_pic_extension;
$date = date("Y");

$pictureData = (string) ($_POST['student_pic_data'] ?? '');
$imageName = null;
if ($pictureData !== '') {
    if (!preg_match('#^data:image/jpeg;base64,#', $pictureData)) {
        $errors['student_pic_error'] = 'ক্রপ করা JPEG ছবি আপলোড করুন';
    } else {
        try {
            $imageName = saveCroppedJpeg(
                $pictureData,
                'student_pic',
                'student_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.jpg',
                100 * 1024,
                108,
                137
            );
            if (!empty($_POST['old_pic'])) {
                safeUnlinkUnderDirectory(__DIR__ . '/student_pic', (string) $_POST['old_pic']);
            }
        } catch (RuntimeException $exception) {
            $errors['student_pic_error'] = $exception->getMessage();
        }
    }
}

$updateFields = ['cls' => $cls, 'sections' => $sections, 'session_year' => $session_year, 'name_eng' => $name_eng, 'name_ban' => $name_ban, 'fname_eng' => $fname_eng, 'fname_ban' => $fname_ban, 'father_nid_no' => $father_nid_no, 'mname_eng' => $mname_eng, 'mname_ban' => $mname_ban, 'mother_nid_no' => $mother_nid_no, 'gender' => $gender, 'religion' => $religion, 'birth_date' => $birth_date, 'birth_reg_no' => $birth_reg_no, 'division' => $division, 'district' => $district, 'upazilla' => $upazilla, 'post_office' => $post_office, 'village' => $village, 'house_name' => $house_name, 'last_class' => $last_class, 'passing_year' => $passing_year, 'last_school_name' => $last_school_name];
if ($imageName !== null) {
    $updateFields['student_pic'] = $imageName;
}
if (empty($errors['student_pic_error'])) {
    $query = preparedUpdateById($conn, 'students_info', $updateFields, $update_id);
    if ($query) {
        header("Location: view_admission_form");
        exit;
    }
    $errors['form_error'] = 'ফর্ম সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।';
}
}
}
}
?>
<?php
$admHero = [
    'kicker' => 'অনলাইন ভর্তি',
    'title' => 'আবেদন ফর্ম সম্পাদনা',
    'text' => 'লগইন করা আবেদনের তথ্য সংশোধন করুন। জন্ম সনদ ও পরিচয়পত্র অনুসারে সঠিক তথ্য রাখুন।',
];
?>
<div class="adm-page">
<?php include("admission_navigation.php")?>
    <section class="adm-wrap">
        <div class="container" id="reg">

                <?php
        $edit_id = (int) $_SESSION['admission_login_id'];
        $sql = "SELECT s.id, cls.id as cls, cls.class_name_ban as cls_name_ban, s.cls_roll, s.sections, s.admission_token, s.session_year, s.name_ban, s.name_eng, s.gender, s.religion, s.fname_ban, s.fname_eng, s.father_nid_no, s.mname_ban, s.mname_eng, s.mother_nid_no, s.mobile, s.birth_date, s.birth_reg_no, s.last_class, s.exam_roll, s.passing_year, s.scholarship_stipend_info, s.last_school_name, s.house_name, s.village, s.post_office, upazilas.id as upazilas_id, districts.id as districts_id, divisions.id as divisions_id, s.student_pic, s.birth_reg_pic FROM students_info s LEFT JOIN cls ON s.cls = cls.id LEFT JOIN sections ON s.sections = sections.id LEFT JOIN divisions ON s.division = divisions.id LEFT JOIN districts ON s.district = districts.id LEFT JOIN upazilas ON s.upazilla = upazilas.id WHERE s.id = ?";
        $statement = $conn->prepare($sql);
        $statement->bind_param('i', $edit_id);
        $statement->execute();
        $query = $statement->get_result();
        $row = ($query instanceof mysqli_result) ? $query->fetch_assoc() : null;
        $statement->close();
        if (!$row) {
            unset($_SESSION['admission_login_id']);
            header('Location: admission_login');
            exit;
        }
        ?>
                    <form id="admissionForm" action="" method="POST" class="needs-validation adm-form adm-sheet" enctype="multipart/form-data" autocomplete="off" novalidate>
                        <?= csrfField() ?>
                        <?php if (isset($errors["csrf_error"])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($errors["csrf_error"], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (isset($errors["form_error"])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($errors["form_error"], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <h6 class="text-danger mb-4">[ লাল চিহ্নিত * ঘরগুলো অবশ্য পূরণীয় ]</h6>
                        <input type="hidden" name="cls_roll_status" value="<?php if (isset($cls_roll_status)) {
                            echo hText((string) ($cls_roll_status ?? ''));
                        }else if (isset($row['cls_roll'])) {
                            echo hText((string) ($row['cls_roll'] ?? ''));
                        } ?>">
                            <input type="hidden" name="sections_status" value="<?php if (isset($sections_status)) {
                                echo hText((string) ($sections_status ?? ''));
                            }else if (isset($row['sections'])) {
                                echo hText((string) ($row['sections'] ?? ''));
                            } ?>">
                            <h5 class="reg-title">০১. প্রাথমিক তথ্য</h5>
                            <div class="row mb-4">
                            <?php
                            
                            if(!empty($row['cls_roll'])){?>
                            
                                
                                                    <div class="row mb-4">
                            <div class="col-md-3 mb-4 mb-md-0 form_field">
                            <label for="cls_roll" class="form-label">রোল <span
                                        class="required">*</span></label>
                                <input type="number" class="form-control cls_roll_input input require <?php
                                if (isset($errors["cls_roll_error"])) {
                                echo 'is-invalid';
                                } else if (isset($cls_roll)) {
                                if (empty($errors["cls_roll_error"])) {
                                echo "is-valid";
                                }
                                } ?>" id="cls_roll" name="cls_roll" value="<?php if (isset($cls_roll)) {
                                    echo hText((string) ($cls_roll ?? ''));
                                    }else if (isset($row['cls_roll'])) {
                                    echo hText((string) ($row['cls_roll'] ?? ''));
                                    } ?>" autocomplete="off" readonly="readonly" placeholder="রোল লিখুন" input disabled  required>
                                <div class="<?php if (isset($errors["cls_roll_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["cls_roll_error"])) {
                                    echo $errors["cls_roll_error"];
                                    }
                                    ?>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label for="cls" class="form-label">শ্রেণী <span
                                        class="required">*</span></label>
                                <select class="form-select input require <?php
                                    if (isset($errors["cls_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($cls)) {
                                    if (empty($errors["cls_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="cls" name="cls" disabled readonly required>
                                    <?php
                    $selectedClass = $cls ?? ($row['cls'] ?? '');
                    echoClassOptions(schoolClasses(publicDatabase(), false), $selectedClass);
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



                            <div class="col-md-3 mb-4 mb-md-0 form_field">
                            <label for="sections" class="form-label">শাখা<span
                                        class="required">*</span></label>
                               

                                        <select class="form-select input require <?php
                                    if (isset($errors["sections_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($sections)) {
                                    if (empty($errors["sections_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="sections" name="sections" disabled readonly required>

                    <?php
                    $selectedSection = (string) ($sections ?? ($row['sections'] ?? ''));
                    $classForSections = (int) ($cls ?? ($row['cls'] ?? 0));
                    echoSectionOptions(
                        schoolSections(publicDatabase(), $classForSections > 0 ? $classForSections : null),
                        $selectedSection,
                        $selectedSection === 'others'
                    );
                    ?>
                </select>

                                        
                                <div class="<?php if (isset($errors["sections_error"])) {
                                    echo 'invalid-feedback';
                                    } ?>">
                                    <?php
                                    if (isset($errors["sections_error"])) {
                                    echo $errors["sections_error"];
                                    }
                                    ?>
                                </div>
                            </div>




                            <div class="col-md-3">
                                <label for="session_year" class="form-label">শিক্ষাবর্ষ <span
                                        class="required">*</span></label>
                                <select class="form-select input require <?php
                                    if (isset($errors["session_year_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($session_year)) {
                                    if (empty($errors["session_year_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="session_year" name="session_year" disabled readonly required>
                                    <?php echoAdmissionSessionYearOptions($session_year ?? ($row['session_year'] ?? '')); ?>
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
                            
                            <?php
                                }else{?>


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
                                    }else if (isset($row['admission_token'])) {
                                    echo hText((string) ($row['admission_token'] ?? ''));
                                    } ?>" autocomplete="off" readonly="readonly" placeholder="টোকেনটি লিখুন"  required>
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
                    $selectedClass = $cls ?? ($row['cls'] ?? '');
                    echoClassOptions(schoolClasses(publicDatabase(), false), $selectedClass);
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
                                    } ?>" id="session_year" name="session_year" required>
                                    <option selected value="">শিক্ষাবর্ষ নির্বাচন করুন।</option>
                                    <?php echoAdmissionSessionYearOptions($session_year ?? ($row['session_year'] ?? '')); ?>
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

                                <?php }
                            
                            ?>
                        
                        </div>
                        <h5 class="reg-title">০২. শিক্ষার্থীর ব্যক্তিগত তথ্য (জন্ম নিবন্ধন অনুসারে) </h5>
                        <div class="row mb-4">
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
                                    echo hText((string) ($name_ban ?? ''));}else if(isset($row['name_ban'])){echo hText((string) ($row['name_ban'] ?? ''));}?>" id="name_ban" placeholder="বাংলায় পুরো নাম লিখুন" required>
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
                                    echo hText((string) ($name_eng ?? ''));}else if(isset($row['name_eng'])){echo hText((string) ($row['name_eng'] ?? ''));}?>" id="name_eng" placeholder="ইংরেজিতে পুরো নাম লিখুন" required>
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
                        <div class="row mb-4">
                            <div class="col-md-3 mb-4 mb-md-0">
                                <label for="gender" class="form-label">জেন্ডার <span class="required">*</span></label>
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
                    }else if(isset($row['gender'])){if(($row['gender']) == 'male'){echo "selected";} } ?>>ছাত্র</option>
                    <option value="female" <?php if (isset($gender)) {
                        if ($gender == "female") {
                        echo "selected";
                        }
                    }else if(isset($row['gender'])){if(($row['gender']) == 'female'){echo "selected";} } ?>>ছাত্রী</option>
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
                    }else if(isset($row['religion'])){if(($row['religion']) == 'Islam'){echo "selected";} } ?>>ইসলাম</option>
                    <option value="Hindu" <?php if (isset($religion)) {
                        if ($religion == "Hindu") {
                        echo "selected";
                        }
                    }else if(isset($row['religion'])){if(($row['religion']) == 'Hindu'){echo "selected";} } ?>>হিন্দু</option>
                    <option value="Buddhist" <?php if (isset($religion)) {
                        if ($religion == "Buddhist") {
                        echo "selected";
                        }
                    }else if(isset($row['religion'])){if(($row['religion']) == 'Buddhist'){echo "selected";} } ?>>বৌদ্ধ</option>
                    <option value="Christian"<?php if (isset($religion)) {
                        if ($religion == "Christian") {
                        echo "selected";
                        }
                    }else if(isset($row['religion'])){if(($row['religion']) == 'Christian'){echo "selected";} } ?>>খ্রিস্টান</option>
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
                            <div class="col-md-3 mb-4 mb-md-0">
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
                                    }else if (isset($row['birth_date'])) {
                                    echo hText((string) ($row['birth_date'] ?? ''));
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
                                    }else if (isset($row['birth_reg_no'])) {
                                    echo hText((string) ($row['birth_reg_no'] ?? ''));
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
                            <div class="adm-photo-frame">
                                <?php
                                $existingPic = trim((string) ($row['student_pic'] ?? ''));
                                $existingPicUrl = $existingPic !== ''
                                    ? studentPhotoUrl($existingPic)
                                    : '';
                                ?>
                                <?php if ($existingPicUrl !== ''): ?>
                                    <img src="<?= h($existingPicUrl) ?>" alt="বর্তমান ছবি" width="108" height="137">
                                <?php else: ?>
                                    পাসপোর্ট<br>আকার
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="form-label" for="studentPicture">শিক্ষার্থীর ছবি</label>
                                <input class="form-control <?= isset($errors['student_pic_error']) ? 'is-invalid' : '' ?>" type="file" id="studentPicture" accept="image/jpeg,image/png,image/webp">
                                <input type="hidden" name="student_pic_data" id="studentPicData" value="<?= h((string) $pictureData) ?>">
                                <input type="hidden" name="old_pic" id="old_pic" value="<?= h((string) ($row['student_pic'] ?? '')) ?>">
                                <small class="text-secondary d-block mt-1">ছবি বদলাতে নতুন ফাইল নির্বাচন করে ক্রপ করুন। ১০৮×১৩৭ পিক্সেল JPEG, সর্বোচ্চ ১০০KB।</small>
                                <?php if (isset($errors['student_pic_error'])): ?>
                                    <div class="invalid-feedback d-block"><?= h($errors['student_pic_error']) ?></div>
                                <?php endif; ?>
                                <div id="studentCropPreview" class="adm-photo-crop mt-2" style="<?= $pictureData === '' ? 'display:none' : '' ?>">
                                    <img id="studentCropImage" alt="ছবির প্রিভিউ"<?= $pictureData !== '' ? ' src="' . h($pictureData) . '"' : '' ?>>
                                </div>
                            </div>
                        </div>
                        <h5 class="reg-title">০৩. পিতা ও মাতার তথ্য (জাতীয় পরিচয়পত্র অনুসারে)</h5>
                        <div class="row my-4">
                            <div class="col-md-6 mb-4 mb-md-0">
                                <h5 class="content-title" style="font-size:19px">পিতার তথ‌্য</h5>
                                <div class="mb-4">
                                    <label for="fname_ban" class="form-label">পিতার নাম (বাংলায়) <span
                                            class="required">*</span></label>
                                    <input type="text" name="fname_ban" value="<?php if (isset($fname_ban)) {
                echo hText((string) ($fname_ban ?? ''));
                }else if (isset($row['fname_ban'])) {
                echo hText((string) ($row['fname_ban'] ?? ''));
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
                }else if (isset($row['fname_eng'])) {
                echo hText((string) ($row['fname_eng'] ?? ''));
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
                }else if (isset($row['father_nid_no'])) {
                echo hText((string) ($row['father_nid_no'] ?? ''));
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
                            <div class="col-md-6">
                                <h5 class="content-title" style="font-size:19px">মাতার তথ‌্য</h5>
                                <div class="mb-4">
                                    <label for="mname_ban" class="form-label">মাতার নাম (বাংলায়) <span
                                            class="required">*</span></label>
                                    <input type="text" name="mname_ban" value="<?php if (isset($mname_ban)) {
                echo hText((string) ($mname_ban ?? ''));
                }else if (isset($row['mname_ban'])) {
                echo hText((string) ($row['mname_ban'] ?? ''));
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
                }else if (isset($row['mname_eng'])) {
                echo hText((string) ($row['mname_eng'] ?? ''));
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
                }else if (isset($row['mother_nid_no'])) {
                echo hText((string) ($row['mother_nid_no'] ?? ''));
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
                        <h5 class="reg-title">০৪. পূর্বে অধ্যয়নকৃত বিদ্যালয়ের তথ্য</h5>
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <label for="last_class" class="form-label">অধ্যয়নকৃত শ্রেণী <span
                                        class="required">*</span></label>
                                <input type="text" name="last_class" class="form-control input bengali_input require <?php
                                    if (isset($errors["last_class_error"])) {
                                    echo 'is-invalid';
                                    } else if (isset($last_class) || isset($row['last_class'])) {
                                    if (empty($errors["last_class_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" value="<?php if (isset($last_class)) {
                                    echo hText((string) ($last_class ?? ''));
                                    }else if (isset($row['last_class'])) {
                                    echo hText((string) ($row['last_class'] ?? ''));
                                    } ?>" id="last_class" placeholder="যেমন: ৫ম / ৮ম" required>
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
                                    <?php echoPreviousSchoolYearOptions($passing_year ?? ($row['passing_year'] ?? '')); ?>


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
                        <div class="row mb-4">
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
                                    }else if (isset($row['last_school_name'])) {
                                    echo hText((string) ($row['last_school_name'] ?? ''));
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
                        <h5 class="reg-title">০৫. পূর্ণাঙ্গ ঠিকানা </h5>
                        <div class="row mb-4">
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
                    $count_row = mysqli_num_rows($query);
                    if ($count_row > 0) {
                    
                    while($divisions_row = mysqli_fetch_assoc($query)){?>
                    <option value="<?php echo (int) $divisions_row['id']; ?>"
                        <?php
                        if(isset($division)){
                        if($division == $divisions_row['id']){
                        echo "selected";
                        }
                        }else if(isset($row['divisions_id'])){
                        if($row['divisions_id'] == $divisions_row['id']){echo "selected";}
                        }
                        ?>>
                    <?php echo hText((string) ($divisions_row['bn_name'] ?? '')); ?></option>
                    <?php
                    
                    }
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
                                    } else if (isset($district)) {
                                    if (empty($errors["district_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="district" name="district" readonly required>
                                    <?php
                    $districtId = isset($district) ? (int) $district : (int) ($row['districts_id'] ?? 0);
                    if ($districtId > 0) {
                    $sql = "SELECT * FROM districts WHERE id = {$districtId}";
                    
                    $query = mysqli_query($conn, $sql);
                    $count_row = $query ? mysqli_num_rows($query) : 0;
                    if ($count_row > 0) {
                    
                    while($districts_row = mysqli_fetch_assoc($query)){?>
                    <option value="<?php echo (int) $districts_row['id']; ?>"
                        <?php
                        if(isset($district)){
                        if($district == $districts_row['id']){
                        echo "selected";
                        }
                        }else if(isset($row['districts_id'])){
                        if($row['districts_id'] == $districts_row['id']){echo "selected";}
                        }
                        ?>>
                    <?php echo htmlspecialchars((string) $districts_row['bn_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php
                    
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
                                    } else if (isset($upazilla)) {
                                    if (empty($errors["upazilla_error"])) {
                                    echo "is-valid";
                                    }
                                    } ?>" id="upazilla" name="upazilla" value="<?php if (isset($upazilla)) {
                                    echo hText((string) ($upazilla ?? ''));
                                    } ?>" readonly required>
                                    <?php
                    $upazillaId = isset($upazilla) ? (int) $upazilla : (int) ($row['upazilas_id'] ?? 0);
                    if ($upazillaId > 0) {
                    $sql = "SELECT * FROM upazilas WHERE id = {$upazillaId}";
                    
                    $query = mysqli_query($conn, $sql);
                    $count_row = $query ? mysqli_num_rows($query) : 0;
                    if ($count_row > 0) {
                    
                    while($upazillas_row = mysqli_fetch_assoc($query)){?>
                    <option value="<?php echo (int) $upazillas_row['id']; ?>"
                        <?php
                        if(isset($upazilla)){
                        if($upazilla == $upazillas_row['id']){
                        echo "selected";
                        }
                        }else if(isset($row['upazilas_id'])){
                        if($row['upazilas_id'] == $upazillas_row['id']){echo "selected";}
                        }
                        ?>>
                    <?php echo htmlspecialchars((string) $upazillas_row['bn_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php
                    
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
                            <div class="col-md-3 mb-4 mb-md-0">
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
                                    }else if (isset($row['post_office'])) {
                                    echo hText((string) ($row['post_office'] ?? ''));
                                    } ?>" placeholder="ডাকঘরের নাম লিখুন" readonly required>
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
                            <div class="col-md-3 mb-4 mb-md-0">
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
                                    }else if (isset($row['village'])) {
                                    echo hText((string) ($row['village'] ?? ''));
                                    } ?>" placeholder="গ্রামের নাম লিখুন" readonly required>
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
                            <div class="col-md-6 mb-4 mb-md-0">
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
                                    }else if (isset($row['house_name'])) {
                                    echo hText((string) ($row['house_name'] ?? ''));
                                    } ?>" placeholder="বাড়ী/বাসার নাম লিখুন" readonly required>
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
                        </div><hr/>


                        <input type="hidden" id="update_id" name="update_id" value="<?php if (isset($_POST['update_id'])) {
              echo hText((string) ($_POST['update_id'] ?? ''));
              }else if(isset($row['id'])){echo hText((string) ($row['id'] ?? ''));}?>">

                        <div class="adm-submit text-center">
                            <button type="submit" id="admission_form_edit" name="admission_form_edit"
                                class="btn btn-sweet">তথ্য আপডেট করুন</button>
                        </div>
                    </form>
        </div>
    </section>
    <!-- =============== Registration Section End =============================== -->
</div>
<!-- history close -->
<?php include('footer.php') ?>
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
                    } else if (type == "post_office") {
                        $("#post_office").html(data);
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
    }
  });
})();
</script>



<script>
document.querySelectorAll("select[readonly] > option:not([selected])").forEach( (el) => el.setAttribute("disabled", "disabled") );
$('form').submit(function (e) {
    e.preventDefault;
        $("#cls_roll").attr("disabled", false);
        $("#cls").attr("disabled", false);
        $("#sections").attr("disabled", false);
        $("#session_year").attr("disabled", false);
    });
</script>
