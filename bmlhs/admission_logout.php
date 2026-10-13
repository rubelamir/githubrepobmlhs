<?php 
require_once __DIR__ . '/includes/security.php';
unset($_SESSION['admission_login_id']);
header("Location: online_admission");
?>
