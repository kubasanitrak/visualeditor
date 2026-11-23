<?php
/**
 * Styling (Phase 2): everything is checked against site/styles.manifest.json.
 * Items and pages get classes from manifest groups and colours from the palette;
 * the global theme gets palette colours and option values.
 */

function ve_admin_manifest(): array
{
    static $manifest = null;
    if ($manifest === null) {
        $manifest = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/site/styles.manifest.json'), true);
        if (!is_array($manifest)) {
            ve_admin_fail('site/styles.manifest.json is missing or not valid JSON.', 500);
        }
        $manifest += ['groups' => [], 'theme' => []];
        $manifest['theme'] += ['palette' => [], 'options' => []];
    }
    return $manifest;
}

/** Manifest groups offered for an item type ('page' for page settings). */
function ve_admin_groups_for(string $type): array
{
    return array_values(array_filter(ve_admin_manifest()['groups'], fn($g) => in_array($type, $g['applies'] ?? [], true)));
}

/**
 * Validate a full class list for an item type: every class must come from a group
 * that applies to the type, and exclusive groups allow one class at most.
 */
function ve_admin_validate_classes(string $type, string $classes): string
{
    $wanted = array_values(array_unique(preg_split('/\s+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    $groupOf = [];
    foreach (ve_admin_groups_for($type) as $group) {
        foreach ($group['classes'] ?? [] as $c) {
            $groupOf[$c['class']] = $group;
        }
    }
    $used = [];
    foreach ($wanted as $class) {
        if (!isset($groupOf[$class])) {
            ve_admin_fail("The style “{$class}” is not available for this " . ve_admin_type_label($type) . '.');
        }
        $g = $groupOf[$class];
        if (!empty($g['exclusive']) && isset($used[$g['id']])) {
            ve_admin_fail("Pick only one option for “{$g['label']}”.");
        }
        $used[$g['id']] = true;
    }
    return implode(' ', $wanted);
}

function ve_admin_palette(): array
{
    return ve_admin_manifest()['theme']['palette'];
}

/** A colour setting: '' / null (none) or a palette token. */
function ve_admin_check_color($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $tokens = array_column(ve_admin_palette(), 'token');
    if (!is_string($value) || !in_array($value, $tokens, true)) {
        ve_admin_fail('Pick a colour from the palette.');
    }
    return $value;
}

/** Apply a style patch to an item: {classes?: "a b", settings?: {bg?, color?}}. */
function ve_admin_style_item(int $id, array $in): void
{
    $item = ve_admin_item($id);
    $set = [];
    if (array_key_exists('classes', $in)) {
        $set['classes'] = ve_admin_validate_classes($item['type'], (string) $in['classes']);
    }
    if (isset($in['settings']) && is_array($in['settings'])) {
        $settings = $item['settings'];
        foreach (['bg', 'color'] as $key) {
            if (array_key_exists($key, $in['settings'])) {
                $color = ve_admin_check_color($in['settings'][$key]);
                if ($color === null) unset($settings[$key]); else $settings[$key] = $color;
            }
        }
        $set['settings'] = ve_admin_encode($settings);
    }
    if ($set) {
        $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set)));
        q("UPDATE items SET $cols WHERE id = ?", [...array_values($set), $id]);
    }
}

/** Apply a style patch to a page: {classes?, bg?, color?}. Returns the new settings. */
function ve_admin_style_page(int $id, array $in): array
{
    $json = q('SELECT settings FROM pages WHERE id = ?', [$id])->fetchColumn();
    if ($json === false) {
        ve_admin_fail('Page not found.', 404);
    }
    $settings = ve_json($json);
    if (array_key_exists('classes', $in)) {
        $settings['classes'] = ve_admin_validate_classes('page', (string) $in['classes']);
        unset($settings['full_width']); // replaced by the page-full class
    }
    foreach (['bg', 'color'] as $key) {
        if (array_key_exists($key, $in)) {
            $color = ve_admin_check_color($in[$key]);
            if ($color === null) unset($settings[$key]); else $settings[$key] = $color;
        }
    }
    q('UPDATE pages SET settings = ? WHERE id = ?', [ve_admin_encode($settings), $id]);
    return $settings;
}

/* ---------- global theme ---------- */

function ve_admin_theme(): array
{
    $json = q("SELECT `value` FROM settings WHERE `key` = 'theme'")->fetchColumn();
    $theme = $json !== false ? ve_json($json) : [];
    $theme['mode'] = ve_theme_mode($theme);
    $theme['vars'] = is_array($theme['vars'] ?? null) ? $theme['vars'] : [];
    $theme['dark'] = is_array($theme['dark'] ?? null) ? $theme['dark'] : [];
    return $theme;
}

/** Current value of every palette colour and option, defaults filled in from the manifest. */
function ve_admin_theme_values(array $theme): array
{
    $out = ['mode' => $theme['mode'], 'colors' => [], 'options' => []];
    foreach (ve_admin_palette() as $p) {
        $var = 'color-' . $p['token'];
        $out['colors'][$p['token']] = [
            'light' => $theme['vars'][$var] ?? $p['light'],
            'dark'  => isset($p['dark']) ? ($theme['dark'][$var] ?? $p['dark']) : null,
        ];
    }
    foreach (ve_admin_manifest()['theme']['options'] as $o) {
        $out['options'][$o['var']] = $theme['vars'][$o['var']] ?? $o['default'] ?? null;
    }
    return $out;
}

/**
 * Update the theme: {mode?, colors?: {token: {light?, dark?}}, options?: {var: value}}.
 * Colours are #rrggbb; options must be one of the manifest choices.
 */
function ve_admin_update_theme(array $in): array
{
    $theme = ve_admin_theme();
    $hex = fn($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v);

    if (array_key_exists('mode', $in)) {
        if (!in_array($in['mode'], ['auto', 'light', 'dark'], true)) ve_admin_fail('Unknown colour mode.');
        $theme['mode'] = $in['mode'];
    }

    $palette = [];
    foreach (ve_admin_palette() as $p) $palette[$p['token']] = $p;
    foreach ((array) ($in['colors'] ?? []) as $token => $values) {
        $p = $palette[$token] ?? ve_admin_fail('Unknown palette colour.');
        $var = 'color-' . $token;
        foreach (['light', 'dark'] as $mode) {
            if (!is_array($values) || !array_key_exists($mode, $values)) continue;
            if ($mode === 'dark' && !isset($p['dark'])) ve_admin_fail("“{$p['label']}” has no dark-mode colour.");
            if (!$hex($values[$mode])) ve_admin_fail('Colours must look like #2f5bff.');
            $theme[$mode === 'light' ? 'vars' : 'dark'][$var] = strtolower($values[$mode]);
        }
        // A colour that changes with the mode always has both values, so dark mode never shows a light value.
        if (isset($p['dark'])) {
            $theme['vars'][$var] ??= $p['light'];
            $theme['dark'][$var] ??= $p['dark'];
        }
    }

    $options = [];
    foreach (ve_admin_manifest()['theme']['options'] as $o) $options[$o['var']] = $o;
    foreach ((array) ($in['options'] ?? []) as $var => $value) {
        $o = $options[$var] ?? ve_admin_fail('Unknown setting.');
        if (!in_array($value, array_column($o['choices'] ?? [], 'value'), true)) ve_admin_fail("Pick one of the choices for “{$o['label']}”.");
        $theme['vars'][$var] = $value;
    }

    q("INSERT INTO settings (`key`, `value`) VALUES ('theme', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [
        json_encode($theme, JSON_UNESCAPED_SLASHES),
    ]);
    return $theme;
}
