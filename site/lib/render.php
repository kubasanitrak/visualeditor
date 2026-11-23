<?php
/**
 * Page renderer. Reads pages and items from the database and renders them
 * with the templates in /site/templates. Knows nothing about the admin core.
 */

require_once __DIR__ . '/db.php';

const VE_CONTAINER_TYPES = ['section', 'row', 'col'];

/* ---------- helpers ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ve_base_url(): string
{
    $configured = ve_config()['base_url'] ?? null;
    if ($configured) {
        return rtrim($configured, '/');
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    // A script in a subfolder (e.g. admin/api.php) still resolves to the project root URL.
    $root = str_replace('\\', '/', (string) realpath(dirname(__DIR__, 2)));
    $scriptDir = str_replace('\\', '/', dirname((string) realpath($_SERVER['SCRIPT_FILENAME'] ?? '')));
    if ($root !== '' && str_starts_with($scriptDir, $root)) {
        $sub = substr($scriptDir, strlen($root));
        if ($sub !== '' && str_ends_with($dir, $sub)) {
            $dir = substr($dir, 0, -strlen($sub));
        }
    }
    return rtrim($dir, '/');
}

function ve_url(string $path = ''): string
{
    return ve_base_url() . '/' . ltrim($path, '/');
}

/** Keep only safe class-name characters. Classes come from the style manifest. */
function ve_classes(?string $classes): string
{
    $parts = preg_split('/\s+/', trim((string) $classes)) ?: [];
    $safe = array_filter($parts, fn($c) => preg_match('/^[a-z][a-z0-9-]*$/', $c));
    return implode(' ', array_unique($safe));
}

/** Colour setting -> CSS value. Palette tokens ("accent") become var(--color-accent). */
function ve_color(?string $value): string
{
    $value = trim((string) $value);
    if (preg_match('/^[a-z][a-z0-9-]*$/', $value)) {
        return 'var(--color-' . $value . ')';
    }
    return preg_replace('/[^#a-zA-Z0-9(),.% -]/', '', $value);
}

/** Plain CSS value from settings (no braces, semicolons or quotes). */
function ve_css_value(string $value): string
{
    return preg_replace('/[^#a-zA-Z0-9(),.% -]/', '', $value);
}

function ve_json(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/* ---------- data ---------- */

function ve_settings(): array
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        foreach (q('SELECT `key`, `value` FROM settings')->fetchAll() as $row) {
            $settings[$row['key']] = json_decode($row['value'], true);
        }
    }
    return $settings;
}

function ve_get_page(string $slug): ?array
{
    $page = q('SELECT * FROM pages WHERE slug = ? AND status = "published" LIMIT 1', [$slug])->fetch();
    if (!$page) {
        return null;
    }
    $page['settings'] = ve_json($page['settings']);
    return $page;
}

/** Current slug of a page that used to live at $oldSlug, or null. */
function ve_redirect_target(string $oldSlug): ?string
{
    try {
        $slug = q('SELECT p.slug FROM redirects r JOIN pages p ON p.id = r.page_id
                   WHERE r.old_slug = ? AND p.status = "published" LIMIT 1', [$oldSlug])->fetchColumn();
    } catch (PDOException) {
        return null; // redirects table not created yet (migration 003)
    }
    return $slug !== false && $slug !== $oldSlug ? $slug : null;
}

/** All items of a page, as a tree ordered by sort. */
function ve_get_items(int $pageId): array
{
    $rows = q('SELECT * FROM items WHERE page_id = ? ORDER BY sort, id', [$pageId])->fetchAll();
    $byParent = [];
    foreach ($rows as $row) {
        $row['content']  = ve_json($row['content']);
        $row['settings'] = ve_json($row['settings']);
        $byParent[(int) $row['parent_id']][] = $row;
    }
    $build = function (int $parentId) use (&$build, $byParent): array {
        $out = [];
        foreach ($byParent[$parentId] ?? [] as $item) {
            $item['children'] = $build((int) $item['id']);
            $out[] = $item;
        }
        return $out;
    };
    return $build(0);
}

function ve_media(?int $id): ?array
{
    static $cache = [];
    if (!$id) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = q('SELECT * FROM media WHERE id = ?', [$id])->fetch() ?: null;
    }
    return $cache[$id];
}

function ve_media_url(?array $media): string
{
    return $media ? ve_url('uploads/' . $media['filename']) : '';
}

/* ---------- rendering ---------- */

function ve_template(string $name, array $vars = []): string
{
    $file = dirname(__DIR__) . '/templates/' . $name . '.php';
    if (!is_file($file)) {
        return '<!-- missing template: ' . e($name) . ' -->';
    }
    extract($vars, EXTR_SKIP);
    ob_start();
    include $file;
    return (string) ob_get_clean();
}

function ve_render_item(array $item): string
{
    $type = preg_replace('/[^a-z0-9_-]/', '', $item['type']);
    $children = '';
    if (in_array($type, VE_CONTAINER_TYPES, true)) {
        foreach ($item['children'] as $child) {
            $children .= ve_render_item($child);
        }
    }

    $s = $item['settings'];
    $style = [];
    $classes = (string) $item['classes'];
    if (!empty($s['bg'])) {
        $style[] = '--item-bg:' . ve_color($s['bg']);
        $classes .= ' has-bg';
    }
    if (!empty($s['color']))  $style[] = '--item-color:' . ve_color($s['color']);
    if (!empty($s['width']))  $style[] = '--item-width:' . preg_replace('/[^a-z0-9.%]/', '', $s['width']);

    return ve_template('items/' . $type, [
        'item'     => $item,
        'content'  => $item['content'],
        'children' => $children,
        // Shared attributes: the editor (when present) finds items by data-ve-id.
        'attrs'    => sprintf(
            'data-ve-id="%d" data-ve-type="%s" class="ve-%s %s"%s',
            $item['id'],
            e($type),
            e($type),
            e(ve_classes($classes)),
            $style ? ' style="' . e(implode(';', $style)) . '"' : ''
        ),
    ]);
}

/** Body class + style for a page's settings (classes from the manifest, bg/color from the palette). */
function ve_page_body(array $page): array
{
    $ps = is_array($page['settings'] ?? null) ? $page['settings'] : [];
    $classes = ve_classes(trim('ve-page ' . ($ps['classes'] ?? '') . (!empty($ps['full_width']) ? ' page-full' : '')));
    $style = [];
    if (!empty($ps['bg']))    $style[] = '--page-bg:' . ve_color($ps['bg']);
    if (!empty($ps['color'])) $style[] = '--page-color:' . ve_color($ps['color']);
    return ['class' => $classes, 'style' => implode(';', $style)];
}

/**
 * Global theme -> CSS custom properties. 'vars' apply everywhere, 'dark' only in
 * dark mode (forced, or "auto" + a dark system setting).
 */
function ve_theme_css(array $theme): string
{
    $block = function (array $vars): string {
        $out = [];
        foreach ($vars as $name => $value) {
            if (is_string($name) && preg_match('/^[a-z0-9-]+$/', $name) && is_scalar($value)) {
                $out[] = '--' . $name . ':' . ve_css_value((string) $value);
            }
        }
        return implode(';', $out);
    };
    $css = '';
    if ($light = $block($theme['vars'] ?? [])) {
        $css .= ':root{' . $light . '}';
    }
    if ($dark = $block($theme['dark'] ?? [])) {
        $css .= ':root[data-theme="dark"]{' . $dark . '}'
              . '@media (prefers-color-scheme: dark){:root[data-theme="auto"]{' . $dark . '}}';
    }
    return $css;
}

function ve_theme_mode(array $theme): string
{
    return in_array($theme['mode'] ?? '', ['light', 'dark', 'auto'], true) ? $theme['mode'] : 'auto';
}

function ve_render_page(array $page): string
{
    $body = '';
    foreach (ve_get_items((int) $page['id']) as $item) {
        $body .= ve_render_item($item);
    }
    return ve_template('layout', [
        'page'     => $page,
        'body'     => $body,
        'settings' => ve_settings(),
        'og'       => ve_media($page['og_image_id'] ? (int) $page['og_image_id'] : null),
    ]);
}
