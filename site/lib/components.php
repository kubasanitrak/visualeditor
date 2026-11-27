<?php
/**
 * Component variants installed by admin/tools/setup.php and the owner's
 * choices for them.
 *
 *  - Installed: site/project.json lists the families and variants; their
 *    family.json / variant.json (copied into site/components/) declare what
 *    the owner may change: option class groups and tokens (CSS custom properties).
 *  - Chosen: settings key 'components', per family:
 *      {"header": {"variant": "header/sticky",
 *                  "classes": "header--split",
 *                  "tokens": {"header-bg": "accent", "header-blend": "difference"}}}
 *
 * Site runtime: no admin knowledge, and everything copes with no variants
 * installed, a missing project.json or a missing setting.
 */

const VE_NAME_RE = '/^[a-z][a-z0-9-]*$/';

/** Reads a JSON file from site/, or null. */
function ve_site_json(string $path): ?array
{
    $raw = @file_get_contents(dirname(__DIR__) . '/' . $path);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Installed families, sorted by family.json 'order':
 *   family => [order, family, label, description,
 *              variants => [id => [id, label, description, class]],
 *              groups   => [manifest-style group + applies [family] + variant (id or null)],
 *              tokens   => [[var, label, type, default, choices?, variant (id or null)]]]
 * A family with no readable variant is left out.
 */
function ve_components(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $project = ve_site_json('project.json');
    foreach ((array) ($project['families'] ?? []) as $family => $ids) {
        if (!is_string($family) || !preg_match(VE_NAME_RE, $family)) continue;
        $def = ve_site_json("components/$family/family.json");
        if (!$def) continue;

        $entry = [
            'order'       => (int) ($def['order'] ?? 50),
            'family'      => $family,
            'label'       => (string) ($def['label'] ?? $family),
            'description' => (string) ($def['description'] ?? ''),
            'variants'    => [],
            'groups'      => ve_component_groups($def['options'] ?? [], $family, null),
            'tokens'      => ve_component_tokens($def['tokens'] ?? [], null),
        ];
        foreach ((array) $ids as $id) {
            if (!is_string($id) || !preg_match('#^' . preg_quote($family, '#') . '/[a-z0-9-]+$#', $id)) continue;
            $v = ve_site_json("components/$id/variant.json");
            if (!$v || ($v['id'] ?? '') !== $id) continue;
            $entry['variants'][$id] = [
                'id'          => $id,
                'label'       => (string) ($v['label'] ?? $id),
                'description' => (string) ($v['description'] ?? ''),
                'class'       => ve_classes($v['class'] ?? ''),
            ];
            $entry['groups'] = array_merge($entry['groups'], ve_component_groups($v['options'] ?? [], $family, $id));
            $entry['tokens'] = array_merge($entry['tokens'], ve_component_tokens($v['tokens'] ?? [], $id));
        }
        if ($entry['variants']) {
            $cache[$family] = $entry;
        }
    }
    uasort($cache, fn($a, $b) => $a['order'] <=> $b['order']); // family.json 'order': position in the editor
    return $cache;
}

function ve_component_groups($options, string $family, ?string $variant): array
{
    $out = [];
    foreach ((array) $options as $g) {
        if (!is_array($g) || empty($g['id']) || empty($g['classes'])) continue;
        $classes = [];
        foreach ((array) $g['classes'] as $c) {
            if (is_array($c) && preg_match(VE_NAME_RE, (string) ($c['class'] ?? ''))) {
                $classes[] = ['class' => $c['class'], 'label' => (string) ($c['label'] ?? $c['class'])];
            }
        }
        $out[] = [
            'id'        => (string) $g['id'],
            'label'     => (string) ($g['label'] ?? $g['id']),
            'exclusive' => !empty($g['exclusive']),
            'default'   => isset($g['default']) && in_array($g['default'], array_column($classes, 'class'), true) ? $g['default'] : null,
            'classes'   => $classes,
            'applies'   => [$family],
            'variant'   => $variant,
        ];
    }
    return $out;
}

function ve_component_tokens($tokens, ?string $variant): array
{
    $out = [];
    foreach ((array) $tokens as $t) {
        if (!is_array($t) || !preg_match(VE_NAME_RE, (string) ($t['var'] ?? ''))) continue;
        $type = ($t['type'] ?? '') === 'choice' ? 'choice' : 'color';
        $token = [
            'var'     => $t['var'],
            'label'   => (string) ($t['label'] ?? $t['var']),
            'type'    => $type,
            'default' => isset($t['default']) ? (string) $t['default'] : null,
            'variant' => $variant,
        ];
        if ($type === 'choice') {
            $token['choices'] = array_values(array_filter((array) ($t['choices'] ?? []), fn($c) => is_array($c) && isset($c['value'])));
            if (!$token['choices']) continue;
        }
        $out[] = $token;
    }
    return $out;
}

/** Groups and tokens that apply with the given variant: family-wide ones + the variant's own. */
function ve_component_active(array $family, string $variant): array
{
    $active = fn($x) => $x['variant'] === null || $x['variant'] === $variant;
    return [
        'groups' => array_values(array_filter($family['groups'], $active)),
        'tokens' => array_values(array_filter($family['tokens'], $active)),
    ];
}

/**
 * The owner's choices for one family, cleaned against what is installed:
 *   [variant, classes (option classes), tokens (var => value), class (variant class + options)]
 * Unknown or no longer installed choices fall back to the first installed
 * variant and to each option group's default. Null if the family is not installed.
 */
function ve_component_state(string $family, ?array $stored = null): ?array
{
    $f = ve_components()[$family] ?? null;
    if (!$f) {
        return null;
    }
    $stored ??= (ve_settings()['components'][$family] ?? []);
    $stored = is_array($stored) ? $stored : [];

    $variant = isset($f['variants'][$stored['variant'] ?? '']) ? $stored['variant'] : array_key_first($f['variants']);
    $active = ve_component_active($f, $variant);

    $wanted = preg_split('/\s+/', trim((string) ($stored['classes'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $classes = [];
    foreach ($active['groups'] as $g) {
        $own = array_column($g['classes'], 'class');
        $picked = array_values(array_intersect($own, $wanted));
        if ($g['exclusive']) $picked = array_slice($picked, 0, 1);
        if (!$picked && $g['default']) $picked = [$g['default']];
        array_push($classes, ...$picked);
    }

    $tokens = [];
    $storedTokens = is_array($stored['tokens'] ?? null) ? $stored['tokens'] : [];
    foreach ($active['tokens'] as $t) {
        $value = $storedTokens[$t['var']] ?? null;
        if (is_string($value) && $value !== '') $tokens[$t['var']] = $value;
    }

    return [
        'variant' => $variant,
        'classes' => $classes,
        'tokens'  => $tokens,
        'class'   => ve_classes(trim($f['variants'][$variant]['class'] . ' ' . implode(' ', $classes))),
    ];
}

/** CSS value of one token: palette colours become var(--color-<token>). */
function ve_component_token_css(array $token, string $value): string
{
    if ($token['type'] === 'color') {
        return $value === 'transparent' ? 'transparent' : ve_color($value);
    }
    return ve_css_value($value);
}

/** The owner's token values for every installed family, as one :root block. */
function ve_components_css(?array $stored = null): string
{
    $stored ??= (ve_settings()['components'] ?? []);
    $out = [];
    foreach (ve_components() as $family => $f) {
        $state = ve_component_state($family, is_array($stored[$family] ?? null) ? $stored[$family] : []);
        $byVar = array_column($f['tokens'], null, 'var');
        foreach ($state['tokens'] as $var => $value) {
            if (isset($byVar[$var])) $out[] = '--' . $var . ':' . ve_component_token_css($byVar[$var], $value);
        }
    }
    return $out ? ':root{' . implode(';', $out) . '}' : '';
}
