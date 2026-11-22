<?php
/** Log out (POST with CSRF token), then back to the site. */

require dirname(__DIR__) . '/site/lib/render.php';
require __DIR__ . '/lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !ve_admin_csrf_ok($_POST['csrf'] ?? null)) {
    http_response_code(400);
    exit('Bad request.');
}
ve_admin_logout();
header('Location: ' . ve_base_url() . '/', true, 303);
