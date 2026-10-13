<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && verifyCsrfToken()) {
    clearAdminTeacherSession();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();

    header('Location: examples/login-v2.php', true, 302);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign out</title>
</head>
<body>
  <form method="post">
    <?= csrfField() ?>
    <p>Sign out of the admin panel?</p>
    <button type="submit">Sign out</button>
  </form>
</body>
</html>
