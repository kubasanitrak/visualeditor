<?php
/** Owner login: email + password. */

require dirname(__DIR__) . '/site/lib/render.php';
require __DIR__ . '/lib/auth.php';

$home = ve_base_url() . '/';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
// Only allow redirects back into this site.
if ($next === '' || !str_starts_with($next, $home) || str_starts_with($next, '//') || preg_match('/[\r\n]/', $next)) {
    $next = $home;
}

if (ve_admin_user()) {
    header('Location: ' . $next);
    exit;
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    if (!ve_admin_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (ve_admin_login($email, (string) ($_POST['password'] ?? ''))) {
        header('Location: ' . $next, true, 303);
        exit;
    } else {
        usleep(400000); // slow down guessing; full rate limiting is Phase 4
        $error = 'Wrong email or password.';
    }
}
$csrf = ve_admin_csrf();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Log in</title>
    <link rel="stylesheet" href="<?= e(ve_url('admin/assets/editor.css')) ?>">
</head>
<body class="ve-admin-login-page">
<form class="ve-admin-ui ve-admin-login" method="post" action="">
    <h1>Log in to edit</h1>
    <?php if ($error): ?><p class="ve-admin-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <label>Email
        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
    </label>
    <label>Password
        <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <button type="submit" class="ve-admin-btn ve-admin-btn-primary">Log in</button>
    <a class="ve-admin-muted" href="<?= e($home) ?>">Back to the site</a>
</form>
</body>
</html>
