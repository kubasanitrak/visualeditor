<?php
$media   = ve_media(isset($content['media_id']) ? (int) $content['media_id'] : null);
$alt     = $content['alt'] ?? ($media['alt'] ?? '');
$caption = $content['caption'] ?? '';
$focal   = $content['focal'] ?? null; // e.g. "30% 60%"
$ratio   = $content['ratio'] ?? null; // e.g. "4/3"
$style   = [];
if ($focal && preg_match('/^\d{1,3}% \d{1,3}%$/', $focal)) $style[] = '--focal:' . $focal;
if ($ratio && preg_match('/^\d+\/\d+$/', $ratio))          $style[] = '--ratio:' . $ratio;
?>
<figure <?= $attrs ?>>
    <div class="ve-media-frame"<?= $style ? ' style="' . e(implode(';', $style)) . '"' : '' ?>>
        <?php if ($media): ?>
        <img src="<?= e(ve_media_url($media)) ?>" alt="<?= e($alt) ?>" loading="lazy"
             <?= $media['width'] ? 'width="' . (int) $media['width'] . '" height="' . (int) $media['height'] . '"' : '' ?>>
        <?php else: ?>
        <div class="ve-placeholder">Image</div>
        <?php endif; ?>
    </div>
    <?php if ($caption !== ''): ?>
    <figcaption><?= e($caption) ?></figcaption>
    <?php endif; ?>
</figure>
