<?php
/** @var array $page @var string $body @var array $settings @var ?array $og */
$config   = ve_config();
$siteName = $settings['site_name'] ?? $config['site_name'] ?? '';
$title    = $page['title'] . ($siteName ? ' – ' . $siteName : '');
$theme    = $settings['theme'] ?? [];
$mode     = ve_theme_mode($theme);
$themeCss = ve_theme_css($theme); // global settings as CSS variables: one change restyles the whole site
$bodyAttr = ve_page_body($page);
?>
<!DOCTYPE html>
<html lang="<?= e($config['lang'] ?? 'en') ?>" data-theme="<?= e($mode) ?>" class="no-js">
<head>
    <meta charset="utf-8">
    <script>document.documentElement.classList.remove('no-js');</script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <?php if (!empty($page['meta_description'])): ?>
    <meta name="description" content="<?= e($page['meta_description']) ?>">
    <?php endif; ?>
    <meta property="og:title" content="<?= e($title) ?>">
    <?php if ($og): ?>
    <meta property="og:image" content="<?= e(ve_media_url($og)) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(ve_url('site/css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(ve_url('site/css/library.css')) ?>">
    <style id="ve-theme"><?= $themeCss ?></style>
</head>
<body class="<?= e($bodyAttr['class']) ?>"<?= $bodyAttr['style'] !== '' ? ' style="' . e($bodyAttr['style']) . '"' : '' ?> data-ve-page="<?= (int) $page['id'] ?>">
<main class="ve-main">
<?= $body ?>
</main>
<script src="<?= e(ve_url('site/js/site.js')) ?>" defer></script>
</body>
</html>
