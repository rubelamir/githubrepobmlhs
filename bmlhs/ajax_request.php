<?php
include "database/connection.php";
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
function test_input($data)
{
$data = trim($data);
$data = stripslashes($data);
$data = htmlspecialchars($data);
return $data;
}

if (isset($_POST["admission_tokens_input"])) {
    header('Content-Type: text/plain; charset=UTF-8');
    $admission_token = trim((string) $_POST["admission_tokens_input"]);
    if ($admission_token === '') {
        echo 'admission_token_error_2';
        exit;
    }
    $statement = $conn->prepare(
        'SELECT t.token_status, s.id AS student_id
         FROM token t
         LEFT JOIN students_info s ON s.admission_token = t.token
         WHERE t.token = ?
         LIMIT 1'
    );
    $statement->bind_param('s', $admission_token);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!is_array($row)) {
        echo 'admission_token_error_2';
        exit;
    }
    if ((int) ($row['token_status'] ?? 0) === 1 || (int) ($row['student_id'] ?? 0) > 0) {
        echo 'admission_token_error_1';
        exit;
    }
    echo 'admission_token_success';
    exit;
}
if (isset($_POST["birth_reg_no_input"])) {
    $str = "";
    $birth_reg_no = mysqli_real_escape_string($conn, test_input($_REQUEST["birth_reg_no_input"]));
    $update_id = filter_input(INPUT_POST, 'update_id', FILTER_VALIDATE_INT);
    $statement = $update_id ? $conn->prepare('SELECT id FROM students_info WHERE birth_reg_no = ? AND id <> ? LIMIT 1') : $conn->prepare('SELECT id FROM students_info WHERE birth_reg_no = ? LIMIT 1');
    if ($update_id) { $statement->bind_param('si', $birth_reg_no, $update_id); } else { $statement->bind_param('s', $birth_reg_no); }
    $statement->execute();
    $query = $statement->get_result();
    $rowCount = $query->num_rows;
    $statement->close();
    if ($rowCount > 0) {
        $str .= "birth_reg_no_already_taken_error";
    }else{
        $str .= "birth_reg_no_success";
    }
    echo $str;
    }
if (isset($_POST["mobile_input"])) {
    $str = "";

    $mobile = mysqli_real_escape_string($conn, test_input($_REQUEST["mobile_input"]));
    $update_id = filter_input(INPUT_POST, 'update_id', FILTER_VALIDATE_INT);
    $statement = $update_id ? $conn->prepare('SELECT id FROM students_info WHERE mobile = ? AND id <> ? LIMIT 1') : $conn->prepare('SELECT id FROM students_info WHERE mobile = ? LIMIT 1');
    if ($update_id) { $statement->bind_param('si', $mobile, $update_id); } else { $statement->bind_param('s', $mobile); }
    $statement->execute();
    $query = $statement->get_result();
    $rowCount = $query->num_rows;
    $statement->close();
    if ($rowCount > 0) {
        $str .= "mobile_already_taken_error";
    }else{
        $str .= "mobile_success";
    }
    echo $str;
    }
if (isset($_POST['image'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Unsupported request.');
}

if(isset($_POST["sections_id"]))
{
 $sections_id = filter_input(INPUT_POST, 'sections_id', FILTER_VALIDATE_INT);
 if (!$sections_id) { exit('Invalid section id'); }
 $statement = $conn->prepare('SELECT id, section_name_ban FROM sections WHERE id = ?');
 $statement->bind_param('i', $sections_id);
 $statement->execute();
 $query = $statement->get_result();
 $row = mysqli_fetch_assoc($query);
 $rowCount = mysqli_num_rows($query);
 $str = "";
 if ($rowCount > 0) {
     $str .= "<option value='" . (int) $row["id"] . "'>" . htmlspecialchars((string) $row["section_name_ban"], ENT_QUOTES, 'UTF-8') . "</option>";
 }else{
     $str .= "<option value=''>Not Found Any Data</option>";
 }
 echo $str;
}
?>
<?php
if(isset($_POST["student_mobile_input"]))
{
 $student_mobile_input = (string) $_POST["student_mobile_input"];
 $statement = $conn->prepare('SELECT id FROM students_info WHERE mobile = ? LIMIT 1');
 $statement->bind_param('s', $student_mobile_input);
 $statement->execute();
 $query = $statement->get_result();
 $rowCount = $query->num_rows;
 $str = "";
 if ($rowCount > 0) {
     $str .= "1";
 }
 echo $str;
}
?>
