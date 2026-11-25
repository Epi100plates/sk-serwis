<?php
/**
 * Biblioteka mediów: bezpieczny upload, walidacja MIME, miniatury WebP,
 * metadane (ALT, tytuł, opis, kategoria) w bazie.
 */

function media_dir(): string
{
    return rtrim((string) cfg('uploads_dir'), '/');
}

function media_url(string $filename): string
{
    return '/images/uploads/' . $filename;
}

function media_list(array $filters = []): array
{
    $sql = 'SELECT * FROM media WHERE 1=1';
    $params = [];
    if (!empty($filters['q'])) {
        $sql .= ' AND (filename LIKE ? OR alt LIKE ? OR title LIKE ? OR description LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    if (!empty($filters['category'])) {
        $sql .= ' AND category = ?';
        $params[] = $filters['category'];
    }
    if (!empty($filters['type'])) {
        if ($filters['type'] === 'image') {
            $sql .= " AND mime LIKE 'image/%'";
        } elseif ($filters['type'] === 'video') {
            $sql .= " AND mime LIKE 'video/%'";
        }
    }
    $sql .= ' ORDER BY id DESC LIMIT 300';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function media_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM media WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function media_upload(array $file, array $meta = []): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'Nieprawidłowy plik.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Błąd uploadu (kod ' . $file['error'] . ').'];
    }
    if ($file['size'] > (int) cfg('media_max_bytes', 12 * 1024 * 1024)) {
        return ['ok' => false, 'error' => 'Plik jest za duży (limit 12 MB).'];
    }

    $tmp = $file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $allowed = (array) cfg('media_allowed_mime', []);
    if (!in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'Niedozwolony typ pliku: ' . $mime];
    }

    // SVG: odrzuć pliki ze skryptami
    if ($mime === 'image/svg+xml') {
        $svg = (string) file_get_contents($tmp);
        if (preg_match('/<script|javascript:|on[a-z]+\s*=|xlink:href\s*=\s*["\']\s*(javascript|data):/i', $svg)) {
            return ['ok' => false, 'error' => 'Plik SVG zawiera niedozwolone elementy skryptowe.'];
        }
    }

    $extMap = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/avif' => 'avif', 'image/gif' => 'gif', 'image/svg+xml' => 'svg',
        'video/mp4' => 'mp4', 'video/webm' => 'webm',
    ];
    $ext = $extMap[$mime];

    $nameBase = slugify((string) ($meta['title'] ?? pathinfo($file['name'], PATHINFO_FILENAME)));
    if ($nameBase === '') {
        $nameBase = 'plik';
    }
    $nameBase = substr($nameBase, 0, 60);
    $filename = date('Ymd') . '-' . $nameBase . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir = media_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Nie można utworzyć katalogu mediów.'];
    }

    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'error' => 'Nie udało się zapisać pliku.'];
    }
    @chmod($target, 0664);

    $width = 0;
    $height = 0;
    if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
        $info = @getimagesize($target);
        if ($info) {
            $width = (int) $info[0];
            $height = (int) $info[1];
        }
    }

    $thumb = media_make_thumb($target, $mime, $filename);

    $st = db()->prepare('INSERT INTO media (filename, path, mime, width, height, bytes, alt, title, description, category, created_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        $filename, media_url($filename), $mime, $width, $height, (int) filesize($target),
        str_limit((string) ($meta['alt'] ?? ''), 300),
        str_limit((string) ($meta['title'] ?? ''), 200),
        str_limit((string) ($meta['description'] ?? ''), 600),
        str_limit((string) ($meta['category'] ?? ''), 80),
        now(), current_user()['username'] ?? 'system',
    ]);

    $record = media_get((int) db()->lastInsertId());
    $record['thumb'] = $thumb;
    git_commit('Panel: dodanie pliku ' . $filename, ['public/images/uploads']);
    audit('media_upload', $filename, ['mime' => $mime, 'bytes' => filesize($target)]);
    return ['ok' => true, 'media' => $record];
}

function media_make_thumb(string $path, string $mime, string $filename): string
{
    if (!str_starts_with($mime, 'image/') || in_array($mime, ['image/svg+xml', 'image/gif'], true)) {
        return '';
    }
    if (!function_exists('imagecreatefromjpeg')) {
        return '';
    }
    $thumbDir = media_dir() . '/thumbs';
    if (!is_dir($thumbDir) && !mkdir($thumbDir, 0775, true) && !is_dir($thumbDir)) {
        return '';
    }

    $source = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : null,
        default => null,
    };
    if (!$source) {
        return '';
    }

    $w = imagesx($source);
    $h = imagesy($source);
    $max = 640;
    $scale = min(1, $max / max($w, $h));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));
    $thumbImg = imagecreatetruecolor($tw, $th);
    imagecopyresampled($thumbImg, $source, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($source);

    $thumbName = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
    $thumbPath = $thumbDir . '/' . $thumbName;
    $ok = function_exists('imagewebp') && imagewebp($thumbImg, $thumbPath, 82);
    if ($ok) {
        @chmod($thumbPath, 0664);
        return '/images/uploads/thumbs/' . $thumbName;
    }
    return '';
}

function media_update(int $id, array $meta): void
{
    $st = db()->prepare('UPDATE media SET alt = ?, title = ?, description = ?, category = ? WHERE id = ?');
    $st->execute([
        str_limit((string) ($meta['alt'] ?? ''), 300),
        str_limit((string) ($meta['title'] ?? ''), 200),
        str_limit((string) ($meta['description'] ?? ''), 600),
        str_limit((string) ($meta['category'] ?? ''), 80),
        $id,
    ]);
    audit('media_update', (string) $id);
}

function media_delete(int $id): void
{
    $record = media_get($id);
    if (!$record) {
        return;
    }
    $filename = basename($record['filename']);
    $path = media_dir() . '/' . $filename;
    if (is_file($path)) {
        unlink($path);
    }
    $thumb = media_dir() . '/thumbs/' . pathinfo($filename, PATHINFO_FILENAME) . '.webp';
    if (is_file($thumb)) {
        unlink($thumb);
    }
    db()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
    git_commit('Panel: usunięcie pliku ' . $filename, ['public/images/uploads']);
    audit('media_delete', $filename);
}

function media_categories(): array
{
    $rows = db()->query("SELECT DISTINCT category FROM media WHERE category <> '' ORDER BY category")->fetchAll();
    return array_map(fn($r) => $r['category'], $rows);
}
