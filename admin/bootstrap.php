<?php
/**
 * Admin hook, loaded by index.php only when this folder exists.
 * For a logged-in owner it injects the editor assets into the rendered page.
 * Visitors get the plain site: no session, no editor code.
 */

require_once __DIR__ . '/lib/auth.php';

(function () {
    $user = ve_admin_user();
    if (!$user) {
        return;
    }

    header('Cache-Control: no-store, private');

    $assets = ve_url('admin/assets/');
    $v = (string) max(array_map('filemtime', [__FILE__, ...glob(__DIR__ . '/assets/*.*'), ...glob(__DIR__ . '/assets/js/*.js')]));
    $config = [
        'api'    => ve_url('admin/api.php'),
        'logout' => ve_url('admin/logout.php'),
        'base'   => ve_base_url(),
        'csrf'   => ve_admin_csrf(),
        'user'   => $user['email'],
    ];
    session_write_close(); // release the session lock while the page renders
    $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

    $head = '<link rel="stylesheet" href="' . e($assets . 'editor.css?v=' . $v) . '">';
    $body = '<script type="application/json" id="ve-admin-config">' . $json . '</script>'
          . '<script type="module" src="' . e($assets . 'editor.js?v=' . $v) . '"></script>';

    ob_start(function (string $html) use ($head, $body): string {
        $h = stripos($html, '</head>');
        if ($h !== false) {
            $html = substr_replace($html, $head . "\n", $h, 0);
        }
        $b = strripos($html, '</body>');
        return $b !== false ? substr_replace($html, $body . "\n", $b, 0) : $html . $body;
    });
})();
