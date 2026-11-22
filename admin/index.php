<?php
/** /admin/ -> the site (when logged in) or the login form. */

require dirname(__DIR__) . '/site/lib/render.php';
require __DIR__ . '/lib/auth.php';

header('Location: ' . (ve_admin_user() ? ve_base_url() . '/' : ve_url('admin/login.php')));
