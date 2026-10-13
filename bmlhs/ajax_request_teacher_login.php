<?php
require_once __DIR__ . '/includes/security.php';
startAppSession();
include "database/connection.php";

// Student / applicant admission login only (staff admin lives in /dist).
if (isset($_POST['studentmobile'])) {
    requireValidCsrf();

    $studentmobile = trim((string) $_POST['studentmobile']);
    $studentpassword = (string) $_POST['studentpassword'];

    if (isLoginRateLimited('admission', $studentmobile)) {
        echo "0";
    } else {
        $statement1 = $conn->prepare('SELECT * FROM students_info WHERE mobile = ? LIMIT 1');
        $statement1->bind_param('s', $studentmobile);
        $statement1->execute();
        $row1 = $statement1->get_result()->fetch_assoc();
        $statement1->close();
        if ($row1 && verifyStoredPassword($studentpassword, (string) $row1['pass'])) {
            migratePasswordIfLegacy($conn, 'students_info', (int) $row1['id'], $studentpassword, (string) $row1['pass']);
            clearLoginFailures('admission', $studentmobile);
            establishAdmissionSession((int) $row1['id']);
            echo "1";
        } else {
            recordLoginFailure('admission', $studentmobile);
            echo "0";
        }
    }
}
