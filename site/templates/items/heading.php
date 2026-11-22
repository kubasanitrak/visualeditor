<?php
$level = (int) ($content['level'] ?? 2);
$level = max(1, min(6, $level));
?>
<h<?= $level ?> <?= $attrs ?>><?= e($content['text'] ?? '') ?></h<?= $level ?>>
