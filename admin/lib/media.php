<?php
/** Media library: upload (images + videos), thumbnails, listing, delete. */

const VE_ADMIN_MIME = [
    'image/jpeg'      => ['jpg', 'image'],
    'image/png'       => ['png', 'image'],
    'image/gif'       => ['gif', 'image'],
    'image/webp'      => ['webp', 'image'],
    'image/avif'      => ['avif', 'image'],
    'video/mp4'       => ['mp4', 'video'],
    'video/webm'      => ['webm', 'video'],
    'video/quicktime' => ['mov', 'video'],
];
const VE_ADMIN_THUMB = 480;

function ve_admin_uploads_dir(): string
{
    return dirname(__DIR__, 2) . '/uploads';
}

function ve_admin_max_upload(): int
{
    $toBytes = function (string $v): int {
        $n = (int) $v;
        return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    };
    $limits = array_filter([
        $toBytes((string) ini_get('upload_max_filesize')),
        $toBytes((string) ini_get('post_max_size')),
        ((int) (ve_config()['upload_max_mb'] ?? 64)) << 20,
    ]);
    return $limits ? min($limits) : 64 << 20;
}

function ve_admin_media_out(array $m, array $used = []): array
{
    return [
        'id'    => (int) $m['id'],
        'kind'  => $m['kind'],
        'name'  => $m['original_name'] ?: basename($m['filename']),
        'url'   => ve_url('uploads/' . $m['filename']),
        'thumb' => $m['thumb'] ? ve_url('uploads/' . $m['thumb']) : ($m['kind'] === 'image' ? ve_url('uploads/' . $m['filename']) : null),
        'width' => $m['width'] ? (int) $m['width'] : null,
        'height'=> $m['height'] ? (int) $m['height'] : null,
        'used'  => $used[(int) $m['id']] ?? 0,
    ];
}

/** How many items / pages reference each media id. */
function ve_admin_media_usage(): array
{
    $used = [];
    foreach (q("SELECT content FROM items WHERE content LIKE '%media_id%' OR content LIKE '%poster_id%'")->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $c = ve_json($json);
        foreach (['media_id', 'poster_id'] as $k) {
            if (!empty($c[$k])) $used[(int) $c[$k]] = ($used[(int) $c[$k]] ?? 0) + 1;
        }
    }
    foreach (q('SELECT og_image_id FROM pages WHERE og_image_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $used[(int) $id] = ($used[(int) $id] ?? 0) + 1;
    }
    return $used;
}

function ve_admin_media_list(): array
{
    $used = ve_admin_media_usage();
    return array_map(fn($m) => ve_admin_media_out($m, $used), q('SELECT * FROM media ORDER BY id DESC')->fetchAll());
}

/** Normalise $_FILES['files'] (multiple) into a flat list. */
function ve_admin_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f) {
        return [];
    }
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        $out[] = ['name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/** Store one uploaded file. Returns the media row (as API output) or throws VeAdminError. */
function ve_admin_store_upload(array $file): array
{
    $name = (string) $file['name'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        ve_admin_fail(match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "$name is too large.",
            UPLOAD_ERR_PARTIAL => "$name was only partly uploaded.",
            UPLOAD_ERR_NO_FILE => 'No file was sent.',
            default => "$name could not be uploaded (error {$file['error']}).",
        });
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        ve_admin_fail("$name is not a valid upload.");
    }
    $max = ve_admin_max_upload();
    if ($file['size'] > $max) {
        ve_admin_fail(sprintf('%s is too large (max %d MB).', $name, $max >> 20));
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset(VE_ADMIN_MIME[$mime])) {
        ve_admin_fail("$name: this file type is not supported. Use JPG, PNG, GIF, WebP, AVIF, MP4, WebM or MOV.");
    }
    [$ext, $kind] = VE_ADMIN_MIME[$mime];

    $sub = date('Y/m');
    $dir = ve_admin_uploads_dir() . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        ve_admin_fail('The uploads folder is not writable.', 500);
    }
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', pathinfo($name, PATHINFO_FILENAME)));
    $base = trim(substr($base, 0, 60), '-') ?: 'file';
    $filename = $sub . '/' . $base . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    $path = ve_admin_uploads_dir() . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        ve_admin_fail("$name could not be saved.", 500);
    }
    @chmod($path, 0644);

    $width = $height = null;
    $thumb = null;
    if ($kind === 'image') {
        $size = @getimagesize($path);
        if (!$size) {
            @unlink($path);
            ve_admin_fail("$name is not a valid image.");
        }
        [$width, $height] = $size;
        $thumb = ve_admin_make_thumb($path, $filename, $mime);
    }

    q('INSERT INTO media (filename, original_name, mime, kind, width, height, size, alt) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $filename, mb_substr($name, 0, 255), $mime, $kind, $width, $height, (int) $file['size'], '',
    ]);
    $id = (int) db()->lastInsertId();
    if ($thumb) {
        q('UPDATE media SET thumb = ? WHERE id = ?', [$thumb, $id]);
    }
    return ve_admin_media_out(q('SELECT * FROM media WHERE id = ?', [$id])->fetch());
}

/** Small WebP (or JPEG) thumbnail for the tray. Returns its path relative to /uploads, or null. */
function ve_admin_make_thumb(string $path, string $filename, string $mime): ?string
{
    if (!function_exists('imagecreatefromstring') || $mime === 'image/gif') {
        return null; // GIFs keep their animation in the tray
    }
    if ($mime === 'image/avif' && !function_exists('imagecreatefromavif')) {
        return null;
    }
    $src = @imagecreatefromstring((string) file_get_contents($path));
    if (!$src) {
        return null;
    }

    // Respect camera orientation, as browsers do.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle && ($rotated = imagerotate($src, $angle, 0))) {
            imagedestroy($src);
            $src = $rotated;
        }
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, VE_ADMIN_THUMB / max($w, $h));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($tw, $th);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

    $dir = dirname($filename) . '/thumbs';
    @mkdir(ve_admin_uploads_dir() . '/' . $dir, 0775, true);
    $webp = function_exists('imagewebp');
    $thumb = $dir . '/' . pathinfo($filename, PATHINFO_FILENAME) . ($webp ? '.webp' : '.jpg');
    $ok = $webp ? imagewebp($dst, ve_admin_uploads_dir() . '/' . $thumb, 80) : imagejpeg($dst, ve_admin_uploads_dir() . '/' . $thumb, 82);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok ? $thumb : null;
}

function ve_admin_media_delete(int $id): void
{
    $m = q('SELECT * FROM media WHERE id = ?', [$id])->fetch();
    if (!$m) {
        ve_admin_fail('Media not found.', 404);
    }
    q('DELETE FROM media WHERE id = ?', [$id]); // pages.og_image_id is cleared by the foreign key
    // Seed files ship with the project; never delete them from disk.
    if (!str_starts_with($m['filename'], 'demo/')) {
        foreach ([$m['filename'], $m['thumb']] as $rel) {
            if ($rel && !str_contains($rel, '..')) {
                @unlink(ve_admin_uploads_dir() . '/' . $rel);
            }
        }
    }
}
