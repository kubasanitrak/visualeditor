<?php
$media  = ve_media(isset($content['media_id']) ? (int) $content['media_id'] : null);
$poster = ve_media(isset($content['poster_id']) ? (int) $content['poster_id'] : null);
$flags  = [];
foreach (['autoplay', 'loop', 'muted', 'controls'] as $flag) {
    if (!empty($content[$flag])) $flags[] = $flag;
}
if (in_array('autoplay', $flags, true)) $flags[] = 'playsinline';
$ratio = $content['ratio'] ?? null;
?>
<figure <?= $attrs ?>>
    <div class="ve-media-frame"<?= ($ratio && preg_match('/^\d+\/\d+$/', $ratio)) ? ' style="--ratio:' . e($ratio) . '"' : '' ?>>
        <?php if ($media): ?>
        <video src="<?= e(ve_media_url($media)) ?>" <?= implode(' ', array_unique($flags)) ?>
               <?= $poster ? 'poster="' . e(ve_media_url($poster)) . '"' : '' ?> preload="metadata"></video>
        <?php else: ?>
        <div class="ve-placeholder">Video</div>
        <?php endif; ?>
    </div>
    <?php if (!empty($content['caption'])): ?>
    <figcaption><?= e($content['caption']) ?></figcaption>
    <?php endif; ?>
</figure>
