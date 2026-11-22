<?php
/**
 * Front controller. Resolves the slug, renders the page from the database,
 * and loads the admin core only if it is present. Without /admin the site
 * keeps working as a plain website.
 */

require __DIR__ . '/site/lib/render.php';

$config = ve_config();
if (!empty($config['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Slug = request path minus the base folder, e.g. /visualeditor/about -> about
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = ve_base_url();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$slug = trim(rawurldecode($path), '/');
if ($slug === '' || $slug === 'index.php') {
    $slug = 'home';
}

// Optional admin layer. Phase 1 adds admin/bootstrap.php (login, edit mode).
$adminBootstrap = __DIR__ . '/admin/bootstrap.php';
if (is_file($adminBootstrap)) {
    require $adminBootstrap;
}

try {
    $page = preg_match('/^[a-z0-9][a-z0-9\/-]*$/', $slug) ? ve_get_page($slug) : null;
} catch (PDOException $ex) {
    http_response_code(500);
    echo !empty($config['debug'])
        ? '<pre>Database error: ' . e($ex->getMessage()) . "\n\nRun admin/install.php to create the tables.</pre>"
        : 'Site temporarily unavailable.';
    exit;
}

if (!$page) {
    http_response_code(404);
    $page = ve_get_page('404');
    if (!$page) {
        echo '<h1>404</h1><p>Page not found.</p>';
        exit;
    }
}

echo ve_render_page($page);
