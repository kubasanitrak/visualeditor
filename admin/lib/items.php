<?php
/**
 * Item tree operations for the editor API: placement rules, insert, duplicate,
 * delete, content updates and HTML sanitising.
 */

/** Which item types each parent accepts. 'root' = top level of a page. */
const VE_ADMIN_ACCEPTS = [
    'root'    => ['section'],
    'section' => ['row', 'heading', 'text', 'image', 'video'],
    'row'     => ['col'],
    'col'     => ['heading', 'text', 'image', 'video'],
];

class VeAdminError extends RuntimeException {}

function ve_admin_fail(string $message, int $status = 400): never
{
    throw new VeAdminError($message, $status);
}

function ve_admin_accepts(string $parentType, string $type): bool
{
    return in_array($type, VE_ADMIN_ACCEPTS[$parentType] ?? [], true);
}

function ve_admin_type_label(string $type): string
{
    return ['col' => 'column'][$type] ?? $type;
}

function ve_admin_encode(array $data): string
{
    return json_encode($data ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ---------- reading ---------- */

function ve_admin_item(int $id): array
{
    $row = q('SELECT * FROM items WHERE id = ?', [$id])->fetch();
    if (!$row) {
        ve_admin_fail('Item not found.', 404);
    }
    $row['content']  = ve_json($row['content']);
    $row['settings'] = ve_json($row['settings']);
    return $row;
}

/** Find an item (with its children) in a page tree from ve_get_items(). */
function ve_admin_find(array $tree, int $id): ?array
{
    foreach ($tree as $node) {
        if ((int) $node['id'] === $id) {
            return $node;
        }
        if ($found = ve_admin_find($node['children'], $id)) {
            return $found;
        }
    }
    return null;
}

function ve_admin_subtree(int $id): array
{
    $item = ve_admin_item($id);
    $node = ve_admin_find(ve_get_items((int) $item['page_id']), $id);
    if (!$node) {
        ve_admin_fail('Item not found.', 404);
    }
    return $node;
}

function ve_admin_render(int $id): string
{
    return ve_render_item(ve_admin_subtree($id));
}

/** Siblings in render order. */
function ve_admin_siblings(int $pageId, ?int $parentId): array
{
    return $parentId
        ? q('SELECT id, sort FROM items WHERE page_id = ? AND parent_id = ? ORDER BY sort, id', [$pageId, $parentId])->fetchAll()
        : q('SELECT id, sort FROM items WHERE page_id = ? AND parent_id IS NULL ORDER BY sort, id', [$pageId])->fetchAll();
}

/** Renumber siblings 10, 20, 30… so there is always room to insert between two. */
function ve_admin_resort(int $pageId, ?int $parentId): void
{
    $n = 0;
    foreach (ve_admin_siblings($pageId, $parentId) as $row) {
        $n += 10;
        if ((int) $row['sort'] !== $n) {
            q('UPDATE items SET sort = ? WHERE id = ?', [$n, $row['id']]);
        }
    }
}

/** Id of the sibling rendered right before $id, or null when it is first. */
function ve_admin_prev_sibling(int $id): ?int
{
    $item = ve_admin_item($id);
    $prev = null;
    foreach (ve_admin_siblings((int) $item['page_id'], $item['parent_id'] ? (int) $item['parent_id'] : null) as $row) {
        if ((int) $row['id'] === $id) {
            return $prev;
        }
        $prev = (int) $row['id'];
    }
    return $prev;
}

/* ---------- placement ---------- */

/**
 * Where does a new item of $type go, relative to the selected item $refId?
 * Returns [parentId|null, afterId|null]; afterId null = append at the end of the parent.
 * Rule: after the selected item if its parent accepts the type, else inside the
 * selected item, else after the nearest ancestor whose parent accepts it.
 */
function ve_admin_place(int $pageId, ?int $refId, string $type): array
{
    if (!$refId) {
        if (ve_admin_accepts('root', $type)) {
            return [null, null];
        }
        ve_admin_fail('Select an item first, so the editor knows where to add the ' . ve_admin_type_label($type) . '.');
    }

    $node = ve_admin_item($refId);
    if ((int) $node['page_id'] !== $pageId) {
        ve_admin_fail('Item is not on this page.');
    }
    $first = true;
    while ($node) {
        $parent = $node['parent_id'] ? ve_admin_item((int) $node['parent_id']) : null;
        if (ve_admin_accepts($parent['type'] ?? 'root', $type)) {
            return [$parent ? (int) $parent['id'] : null, (int) $node['id']];
        }
        if ($first && ve_admin_accepts($node['type'], $type)) {
            return [(int) $node['id'], null];
        }
        $first = false;
        $node = $parent;
    }
    ve_admin_fail('A ' . ve_admin_type_label($type) . ' cannot go here.');
}

/* ---------- writing ---------- */

/** Insert an item tree (from a component preset or a copy). Returns the new root id. */
function ve_admin_insert_tree(int $pageId, ?int $parentId, int $sort, array $node): int
{
    $type = (string) ($node['type'] ?? '');
    if (!is_file(dirname(__DIR__, 2) . '/site/templates/items/' . basename($type) . '.php')) {
        ve_admin_fail('Unknown item type: ' . $type);
    }
    q('INSERT INTO items (page_id, parent_id, sort, type, content, classes, settings) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $pageId,
        $parentId,
        $sort,
        $type,
        ve_admin_encode(is_array($node['content'] ?? null) ? $node['content'] : []),
        ve_admin_filter_classes((string) ($node['classes'] ?? '')),
        ve_admin_encode(is_array($node['settings'] ?? null) ? $node['settings'] : []),
    ]);
    $id = (int) db()->lastInsertId();
    $n = 0;
    foreach (($node['children'] ?? []) as $child) {
        if (!ve_admin_accepts($type, (string) ($child['type'] ?? ''))) {
            ve_admin_fail('A ' . ve_admin_type_label($child['type'] ?? '?') . ' cannot go inside a ' . ve_admin_type_label($type) . '.');
        }
        ve_admin_insert_tree($pageId, $id, $n += 10, $child);
    }
    return $id;
}

/** Insert $tree at the given place. Returns the new item id. */
function ve_admin_insert_at(int $pageId, ?int $parentId, ?int $afterId, array $tree): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        ve_admin_resort($pageId, $parentId);
        if ($afterId) {
            $sort = (int) q('SELECT sort FROM items WHERE id = ?', [$afterId])->fetchColumn() + 5;
        } else {
            $sort = (count(ve_admin_siblings($pageId, $parentId)) + 1) * 10;
        }
        $id = ve_admin_insert_tree($pageId, $parentId, $sort, $tree);
        ve_admin_resort($pageId, $parentId);
        $pdo->commit();
        return $id;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function ve_admin_add_component(int $pageId, ?int $refId, string $componentId): int
{
    $component = null;
    foreach (ve_admin_components() as $c) {
        if ($c['id'] === $componentId) {
            $component = $c;
        }
    }
    if (!$component) {
        ve_admin_fail('Unknown component.');
    }
    if (!q('SELECT id FROM pages WHERE id = ?', [$pageId])->fetchColumn()) {
        ve_admin_fail('Page not found.', 404);
    }
    [$parentId, $afterId] = ve_admin_place($pageId, $refId, $component['tree']['type']);
    return ve_admin_insert_at($pageId, $parentId, $afterId, $component['tree']);
}

function ve_admin_duplicate(int $id): int
{
    $node = ve_admin_subtree($id);
    $parentId = $node['parent_id'] ? (int) $node['parent_id'] : null;
    return ve_admin_insert_at((int) $node['page_id'], $parentId, $id, $node);
}

function ve_admin_delete(int $id): void
{
    ve_admin_item($id);
    q('DELETE FROM items WHERE id = ?', [$id]); // children go with it (ON DELETE CASCADE)
}

/** Merge a content patch into an item. Only fields that make sense for its type are accepted. */
function ve_admin_update_content(int $id, array $patch): array
{
    $item = ve_admin_item($id);
    $content = $item['content'];
    $str = fn($v, int $max) => mb_substr(trim(strip_tags((string) $v)), 0, $max);

    switch ($item['type']) {
        case 'heading':
            if (array_key_exists('text', $patch)) $content['text'] = $str(preg_replace('/\s+/u', ' ', (string) $patch['text']), 500);
            break;
        case 'text':
            if (array_key_exists('html', $patch)) $content['html'] = ve_admin_clean_html((string) $patch['html']);
            break;
        case 'image':
        case 'video':
            if (array_key_exists('media_id', $patch)) {
                $mediaId = (int) $patch['media_id'];
                $kind = q('SELECT kind FROM media WHERE id = ?', [$mediaId])->fetchColumn();
                if ($kind !== $item['type']) {
                    ve_admin_fail('Pick a' . ($item['type'] === 'image' ? 'n image.' : ' video.'));
                }
                $content['media_id'] = $mediaId;
            }
            if (array_key_exists('caption', $patch)) $content['caption'] = $str($patch['caption'], 500);
            if (array_key_exists('alt', $patch)) {
                $alt = $str($patch['alt'], 255);
                // Empty = fall back to the alt text stored with the media file.
                if ($alt === '') unset($content['alt']); else $content['alt'] = $alt;
            }
            break;
        default:
            ve_admin_fail('This item has no editable content.');
    }

    q('UPDATE items SET content = ? WHERE id = ?', [ve_admin_encode($content), $id]);
    return $content;
}

/* ---------- presets, classes, sanitising ---------- */

function ve_admin_components(): array
{
    $file = dirname(__DIR__, 2) . '/site/components.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return array_values(array_filter($data['components'] ?? [], fn($c) => !empty($c['id']) && !empty($c['tree']['type'])));
}

/** Keep only classes registered in the style manifest. */
function ve_admin_filter_classes(string $classes): string
{
    static $known = null;
    if ($known === null) {
        $known = [];
        $manifest = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/site/styles.manifest.json'), true);
        foreach ($manifest['groups'] ?? [] as $group) {
            foreach ($group['classes'] ?? [] as $c) {
                $known[$c['class']] = true;
            }
        }
    }
    $parts = preg_split('/\s+/', trim($classes)) ?: [];
    return implode(' ', array_values(array_unique(array_filter($parts, fn($c) => isset($known[$c])))));
}

/** Rich text from contenteditable -> a small, safe subset of HTML. */
function ve_admin_clean_html(string $html): string
{
    $allowed = ['p', 'br', 'strong', 'em', 'b', 'i', 'a', 'ul', 'ol', 'li'];
    $drop = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'head', 'title', 'meta', 'link'];

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = null;
    foreach ($doc->childNodes as $n) {
        if ($n instanceof DOMElement) {
            $root = $n;
        }
    }
    if (!$root) {
        return '';
    }

    $clean = function (DOMNode $parent) use (&$clean, $doc, $allowed, $drop): void {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMComment || $node instanceof DOMProcessingInstruction) {
                $parent->removeChild($node);
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->nodeName);
            if (in_array($tag, $drop, true)) {
                $parent->removeChild($node);
                continue;
            }
            $clean($node);
            if ($tag === 'div' || $tag === 'h1' || $tag === 'h2' || $tag === 'h3' || $tag === 'h4' || $tag === 'blockquote') {
                // Block wrappers from paste or Enter in some browsers become paragraphs.
                $p = $doc->createElement('p');
                while ($node->firstChild) {
                    $p->appendChild($node->firstChild);
                }
                $parent->replaceChild($p, $node);
                continue;
            }
            if (!in_array($tag, $allowed, true)) {
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }
            $href = $tag === 'a' ? trim($node->getAttribute('href')) : '';
            foreach (iterator_to_array($node->attributes) as $attr) {
                $node->removeAttribute($attr->nodeName);
            }
            if ($tag === 'a') {
                $safe = $href !== '' && !preg_match('/^\s*(javascript|data|vbscript):/i', $href);
                $node->setAttribute('href', $safe ? $href : '#');
                if (preg_match('#^https?://#i', $href)) {
                    $node->setAttribute('rel', 'noopener');
                }
            }
        }
    };
    $clean($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    $out = trim(preg_replace('#<p>(\s|<br>|&nbsp;)*</p>#', '', $out));
    return mb_substr($out, 0, 20000);
}
