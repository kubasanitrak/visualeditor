<?php
/**
 * Editor JSON API. Every call needs a logged-in owner; every POST needs the
 * CSRF token in the X-VE-CSRF header.
 *
 *   GET  ?action=components            component presets
 *   GET  ?action=media.list            media tray
 *   POST ?action=media.upload          multipart files[]
 *   POST ?action=media.delete          {id}
 *   POST ?action=item.update           {id, content: {...}}   -> {html}
 *   POST ?action=item.add              {page_id, ref_id, component} -> {id, html, parent_id, after_id}
 *   POST ?action=item.duplicate        {id}                   -> {id, html, parent_id, after_id}
 *   POST ?action=item.delete           {id}
 *   GET  ?action=page.get&id=          page meta
 *   POST ?action=page.update           {id, title?, slug?, meta_description?, og_image_id?}
 */

require dirname(__DIR__) . '/site/lib/render.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/items.php';
require __DIR__ . '/lib/media.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ve_api_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? '');

if (!ve_admin_user()) {
    ve_api_out(['error' => 'You are logged out. Reload the page and log in again.'], 401);
}
if ($method === 'POST' && !ve_admin_csrf_ok($_SERVER['HTTP_X_VE_CSRF'] ?? null)) {
    ve_api_out(['error' => 'Security token expired. Reload the page.'], 403);
}
session_write_close();

$in = [];
if ($method === 'POST' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        ve_api_out(['error' => 'Invalid request.'], 400);
    }
}
$id = (int) ($in['id'] ?? $_GET['id'] ?? 0);

/** Output for a newly inserted item: where the editor should put it in the DOM. */
function ve_api_placed(int $newId): array
{
    $item = ve_admin_item($newId);
    return [
        'id'        => $newId,
        'html'      => ve_admin_render($newId),
        'parent_id' => $item['parent_id'] ? (int) $item['parent_id'] : null,
        'after_id'  => ve_admin_prev_sibling($newId),
    ];
}

function ve_api_page(int $id): array
{
    $page = q('SELECT id, slug, title, meta_description, og_image_id, status FROM pages WHERE id = ?', [$id])->fetch();
    if (!$page) {
        ve_admin_fail('Page not found.', 404);
    }
    $og = $page['og_image_id'] ? q('SELECT * FROM media WHERE id = ?', [(int) $page['og_image_id']])->fetch() : null;
    return [
        'id'               => (int) $page['id'],
        'slug'             => $page['slug'],
        'title'            => $page['title'],
        'meta_description' => $page['meta_description'],
        'slug_locked'      => in_array($page['slug'], ['home', '404'], true),
        'url'              => ve_url($page['slug'] === 'home' ? '' : $page['slug']),
        'og'               => $og ? ve_admin_media_out($og) : null,
    ];
}

function ve_api_page_update(int $id, array $in): array
{
    $page = ve_api_page($id);
    $set = [];

    if (array_key_exists('title', $in)) {
        $title = mb_substr(trim(strip_tags((string) $in['title'])), 0, 255);
        if ($title === '') ve_admin_fail('The title cannot be empty.');
        $set['title'] = $title;
    }
    if (array_key_exists('meta_description', $in)) {
        $set['meta_description'] = mb_substr(trim(strip_tags((string) $in['meta_description'])), 0, 500);
    }
    if (array_key_exists('slug', $in)) {
        $slug = strtolower(trim((string) $in['slug'], " /\t\n"));
        if ($slug !== $page['slug']) {
            if ($page['slug_locked']) ve_admin_fail('The address of this page cannot change.');
            if (!preg_match('/^[a-z0-9][a-z0-9\/-]*$/', $slug) || str_contains($slug, '//') || strlen($slug) > 191) {
                ve_admin_fail('Use lowercase letters, numbers and dashes, e.g. my-project.');
            }
            $top = explode('/', $slug)[0];
            if (in_array($top, ['admin', 'site', 'uploads', 'config', 'docs', 'home', '404', 'index.php'], true)) {
                ve_admin_fail("“{$slug}” is reserved. Choose another address.");
            }
            if (q('SELECT id FROM pages WHERE slug = ? AND id <> ?', [$slug, $id])->fetchColumn()) {
                ve_admin_fail('Another page already uses this address.');
            }
            $set['slug'] = $slug;
        }
    }
    if (array_key_exists('og_image_id', $in)) {
        $og = $in['og_image_id'] ? (int) $in['og_image_id'] : null;
        if ($og && q('SELECT kind FROM media WHERE id = ?', [$og])->fetchColumn() !== 'image') {
            ve_admin_fail('The share image must be an image.');
        }
        $set['og_image_id'] = $og;
    }

    if ($set) {
        $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set)));
        q("UPDATE pages SET $cols WHERE id = ?", [...array_values($set), $id]);
    }
    return ve_api_page($id);
}

try {
    $route = $method . ' ' . $action;
    switch ($route) {
        case 'GET components':
            ve_api_out(['components' => array_map(
                fn($c) => ['id' => $c['id'], 'label' => $c['label'] ?? $c['id'], 'type' => $c['tree']['type']],
                ve_admin_components()
            )]);

        case 'GET media.list':
            ve_api_out(['media' => ve_admin_media_list(), 'max_mb' => ve_admin_max_upload() >> 20]);

        case 'POST media.upload':
            $files = ve_admin_files('files');
            if (!$files) {
                ve_admin_fail(empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
                    ? sprintf('The upload is too large (max %d MB in total).', ve_admin_max_upload() >> 20)
                    : 'No files were sent.');
            }
            $media = $errors = [];
            foreach ($files as $file) {
                try {
                    $media[] = ve_admin_store_upload($file);
                } catch (VeAdminError $ex) {
                    $errors[] = $ex->getMessage();
                }
            }
            ve_api_out(['media' => $media, 'errors' => $errors], $media ? 200 : 400);

        case 'POST media.delete':
            ve_admin_media_delete($id);
            ve_api_out(['ok' => true]);

        case 'POST item.update':
            ve_admin_update_content($id, is_array($in['content'] ?? null) ? $in['content'] : []);
            ve_api_out(['html' => ve_admin_render($id)]);

        case 'POST item.add':
            $newId = ve_admin_add_component((int) ($in['page_id'] ?? 0), !empty($in['ref_id']) ? (int) $in['ref_id'] : null, (string) ($in['component'] ?? ''));
            ve_api_out(ve_api_placed($newId));

        case 'POST item.duplicate':
            ve_api_out(ve_api_placed(ve_admin_duplicate($id)));

        case 'POST item.delete':
            ve_admin_delete($id);
            ve_api_out(['ok' => true]);

        case 'GET page.get':
            ve_api_out(['page' => ve_api_page($id)]);

        case 'POST page.update':
            ve_api_out(['page' => ve_api_page_update($id, $in)]);

        default:
            ve_api_out(['error' => 'Unknown action.'], 404);
    }
} catch (VeAdminError $ex) {
    ve_api_out(['error' => $ex->getMessage()], $ex->getCode() ?: 400);
} catch (Throwable $ex) {
    error_log('[visualeditor] ' . $ex);
    ve_api_out(['error' => !empty(ve_config()['debug']) ? $ex->getMessage() : 'Something went wrong. Please try again.'], 500);
}
