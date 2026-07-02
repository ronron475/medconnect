<?php
/**
 * Profile picture storage and rendering helpers.
 */

const PROFILE_PICTURE_MAX_BYTES = 2 * 1024 * 1024;

const PROFILE_PICTURE_ALLOWED_MIMES = [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/webp',
];

const PROFILE_PICTURE_ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'webp'];

function profile_picture_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('profile_picture', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL DEFAULT NULL AFTER phone");
    }

    $dir = profile_picture_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $done = true;
}

function profile_picture_storage_dir(): string
{
    return STORAGE_PATH . '/uploads/profile_pictures';
}

function profile_picture_initials(?string $first, ?string $last): string
{
    return strtoupper(
        substr((string) $first, 0, 1) .
        substr((string) $last, 0, 1)
    ) ?: 'U';
}

function profile_picture_public_url(?string $filename): ?string
{
    if ($filename === null || trim($filename) === '') {
        return null;
    }

    $safe = basename($filename);
    if (!preg_match('/^user_\d+_[a-f0-9]{12}\.(jpe?g|png|webp)$/i', $safe)) {
        return null;
    }

    return ASSET_BASE . '/app/api/profile/picture.php?f=' . rawurlencode($safe);
}

function profile_picture_load_for_user(PDO $pdo, int $user_id): ?string
{
    profile_picture_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $value = $stmt->fetchColumn();

    return $value ? (string) $value : null;
}

function profile_picture_sync_session(PDO $pdo, int $user_id): void
{
    $_SESSION['profile_picture'] = profile_picture_load_for_user($pdo, $user_id);
}

/**
 * @return array{success:bool,message:string,url?:string}
 */
function profile_picture_upload(PDO $pdo, int $user_id, array $file): array
{
    profile_picture_ensure_schema($pdo);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'message' => 'Please choose a profile picture to upload.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed. Please try again.'];
    }

    if (($file['size'] ?? 0) > PROFILE_PICTURE_MAX_BYTES) {
        return ['success' => false, 'message' => 'Profile picture must be 2 MB or smaller.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['success' => false, 'message' => 'Invalid upload.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($tmp);
    if (!in_array($mime, PROFILE_PICTURE_ALLOWED_MIMES, true)) {
        return ['success' => false, 'message' => 'Only JPG, PNG, or WEBP images are allowed.'];
    }

    $ext = match ($mime) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $filename = sprintf('user_%d_%s.%s', $user_id, bin2hex(random_bytes(6)), $ext);
    $dest     = profile_picture_storage_dir() . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        return ['success' => false, 'message' => 'Could not save profile picture.'];
    }

    $old = profile_picture_load_for_user($pdo, $user_id);
    if ($old) {
        $old_path = profile_picture_storage_dir() . '/' . basename($old);
        if (is_file($old_path)) {
            @unlink($old_path);
        }
    }

    $stmt = $pdo->prepare('UPDATE users SET profile_picture = ? WHERE id = ?');
    $stmt->execute([$filename, $user_id]);

    $_SESSION['profile_picture'] = $filename;

    return [
        'success' => true,
        'message' => 'Profile picture updated.',
        'url'     => profile_picture_public_url($filename),
    ];
}

function profile_picture_render(
    string $initials,
    ?string $picture_url,
    string $class = '',
    string $size = 'md'
): string {
    $classes = trim('profile-avatar profile-avatar--' . $size . ' ' . $class);
    $initials = htmlspecialchars($initials, ENT_QUOTES, 'UTF-8');

    if ($picture_url) {
        $url = htmlspecialchars($picture_url, ENT_QUOTES, 'UTF-8');

        return '<div class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '">'
            . '<img src="' . $url . '" alt="Profile picture" class="profile-avatar__img" data-profile-avatar-img loading="lazy">'
            . '</div>';
    }

    return '<div class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '">'
        . '<span class="profile-avatar__initials">' . $initials . '</span>'
        . '</div>';
}
