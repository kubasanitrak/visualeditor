<?php
// Rich text from the editor. Only a small set of inline tags is allowed through.
$html = strip_tags((string) ($content['html'] ?? ''), '<p><br><strong><em><b><i><a><ul><ol><li>');
$html = preg_replace('/\s(on\w+|style)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
$html = preg_replace('/href\s*=\s*(["\'])\s*javascript:[^"\']*\1/i', 'href="#"', $html);
?>
<div <?= $attrs ?>><?= $html ?></div>
