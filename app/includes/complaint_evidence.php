<?php
/**
 * Optional supporting evidence (photo/video) for chief complaint — doctor review only.
 * Never passed to NLP or triage engines.
 */

declare(strict_types=1);

const COMPLAINT_EVIDENCE_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const COMPLAINT_EVIDENCE_VIDEO_MAX_BYTES = 25 * 1024 * 1024;

const COMPLAINT_EVIDENCE_IMAGE_MIMES = [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/webp',
];

const COMPLAINT_EVIDENCE_VIDEO_MIMES = [
    'video/mp4',
    'video/webm',
];

function complaint_evidence_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS complaint_evidence (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            patient_id INT UNSIGNED NOT NULL,
            triage_result_id INT UNSIGNED NOT NULL,
            media_type ENUM('image', 'video') NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NULL,
            mime_type VARCHAR(128) NOT NULL,
            file_size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by_user_id INT UNSIGNED NOT NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_complaint_evidence_triage (triage_result_id),
            KEY idx_complaint_evidence_patient (patient_id),
            KEY idx_complaint_evidence_uploaded (uploaded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $dir = complaint_evidence_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM complaint_evidence')->fetchAll(PDO::FETCH_COLUMN);
        if ($cols && !in_array('consultation_id', $cols, true)) {
            $pdo->exec('ALTER TABLE complaint_evidence ADD COLUMN consultation_id INT UNSIGNED NULL AFTER triage_result_id');
        }
    } catch (PDOException $e) {
        // Non-fatal on legacy schemas.
    }

    $done = true;
}

function complaint_evidence_storage_dir(): string
{
    return STORAGE_PATH . '/uploads/complaint_evidence';
}

/**
 * @param array<string, mixed>|null $file
 * @return array{error:string}|null Null when no file or valid.
 */
function complaint_evidence_validate_upload(?array $file): ?array
{
    if ($file === null || !is_array($file)) {
        return null;
    }

    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['error' => 'Supporting evidence upload failed. Please try again.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'Invalid supporting evidence upload.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $size = (int) ($file['size'] ?? 0);

    if (in_array($mime, COMPLAINT_EVIDENCE_IMAGE_MIMES, true)) {
        if ($size > COMPLAINT_EVIDENCE_IMAGE_MAX_BYTES) {
            return ['error' => 'Photo must be 5 MB or smaller.'];
        }
        return null;
    }

    if (in_array($mime, COMPLAINT_EVIDENCE_VIDEO_MIMES, true)) {
        if ($size > COMPLAINT_EVIDENCE_VIDEO_MAX_BYTES) {
            return ['error' => 'Video must be 25 MB or smaller.'];
        }
        return null;
    }

    return ['error' => 'Only JPG, PNG, WEBP photos or MP4/WebM videos are allowed.'];
}

/**
 * @param array<string, mixed> $file
 * @return array{success:bool,message:string,id?:int}
 */
function complaint_evidence_save_for_triage(PDO $pdo, int $patientId, int $triageId, array $file): array
{
    complaint_evidence_ensure_schema($pdo);

    if ($triageId <= 0 || $patientId <= 0) {
        return ['success' => false, 'message' => 'Invalid triage reference.'];
    }

    $validation = complaint_evidence_validate_upload($file);
    if ($validation !== null) {
        return ['success' => false, 'message' => (string) $validation['error']];
    }

    $tmp = (string) $file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $mediaType = in_array($mime, COMPLAINT_EVIDENCE_IMAGE_MIMES, true) ? 'image' : 'video';
    $ext = match ($mime) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'video/webm' => 'webm',
        'video/mp4'  => 'mp4',
        default      => 'jpg',
    };

    $stored = sprintf('evidence_%d_%d_%s.%s', $patientId, $triageId, bin2hex(random_bytes(8)), $ext);
    $dest = complaint_evidence_storage_dir() . '/' . $stored;

    if (!move_uploaded_file($tmp, $dest)) {
        return ['success' => false, 'message' => 'Could not save supporting evidence.'];
    }

    if ($mediaType === 'image') {
        complaint_evidence_strip_image_exif($dest, $mime);
    }

    $original = basename((string) ($file['name'] ?? 'evidence.' . $ext));
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 && is_file($dest)) {
        $size = (int) filesize($dest);
    }

    $existing = complaint_evidence_find_for_triage($pdo, $triageId);
    if ($existing !== null) {
        complaint_evidence_delete_file((string) ($existing['stored_filename'] ?? ''));
        $pdo->prepare('DELETE FROM complaint_evidence WHERE id = ?')->execute([(int) $existing['id']]);
    }

    $uploadedBy = (int) ($_SESSION['user_id'] ?? $patientId);
    $stmt = $pdo->prepare('
        INSERT INTO complaint_evidence
            (patient_id, triage_result_id, media_type, stored_filename, original_filename,
             mime_type, file_size_bytes, uploaded_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $patientId,
        $triageId,
        $mediaType,
        $stored,
        $original,
        $mime,
        $size,
        $uploadedBy,
    ]);

    return [
        'success' => true,
        'message' => 'Supporting evidence saved.',
        'id'      => (int) $pdo->lastInsertId(),
    ];
}

function complaint_evidence_delete_file(string $storedFilename): void
{
    $safe = basename($storedFilename);
    if (!preg_match('/^evidence_\d+_\d+_[a-f0-9]{16}\.(jpe?g|png|webp|mp4|webm)$/i', $safe)) {
        return;
    }
    $path = complaint_evidence_storage_dir() . '/' . $safe;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * @return array<string, mixed>|null
 */
function complaint_evidence_find_for_triage(PDO $pdo, int $triageId): ?array
{
    if ($triageId <= 0) {
        return null;
    }
    complaint_evidence_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT id, patient_id, triage_result_id, media_type, stored_filename, original_filename,
               mime_type, file_size_bytes, uploaded_at
        FROM complaint_evidence
        WHERE triage_result_id = ?
        LIMIT 1
    ');
    $stmt->execute([$triageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array<string, mixed>|null
 */
function complaint_evidence_find_by_id(PDO $pdo, int $evidenceId): ?array
{
    if ($evidenceId <= 0) {
        return null;
    }
    complaint_evidence_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT id, patient_id, triage_result_id, media_type, stored_filename, original_filename,
               mime_type, file_size_bytes, uploaded_at
        FROM complaint_evidence
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$evidenceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function complaint_evidence_view_url(int $evidenceId): string
{
    return ASSET_BASE . '/app/api/complaint_evidence/view.php?id=' . $evidenceId;
}

function complaint_evidence_is_allowed_mime(string $mime): bool
{
    $mime = strtolower(trim($mime));

    return in_array($mime, array_merge(COMPLAINT_EVIDENCE_IMAGE_MIMES, COMPLAINT_EVIDENCE_VIDEO_MIMES), true);
}

function complaint_evidence_format_file_type_label(string $mime, string $mediaType): string
{
    $mime = strtolower(trim($mime));
    $map = [
        'image/jpeg' => 'JPEG photo',
        'image/jpg'  => 'JPEG photo',
        'image/png'  => 'PNG photo',
        'image/webp' => 'WEBP photo',
        'video/mp4'  => 'MP4 video',
        'video/webm' => 'WebM video',
    ];
    if (isset($map[$mime])) {
        return $map[$mime];
    }

    return $mediaType === 'video' ? 'Video' : 'Photo';
}

function complaint_evidence_format_file_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / (1024 * 1024), 1) . ' MB';
}

/**
 * Verify stored evidence file is safe to stream (type/size on disk).
 */
function complaint_evidence_validate_stored_file(string $path, array $row): bool
{
    if (!is_file($path)) {
        return false;
    }

    $mediaType = (string) ($row['media_type'] ?? '');
    $size = (int) filesize($path);
    if ($mediaType === 'video' && $size > COMPLAINT_EVIDENCE_VIDEO_MAX_BYTES) {
        return false;
    }
    if ($mediaType === 'image' && $size > COMPLAINT_EVIDENCE_IMAGE_MAX_BYTES) {
        return false;
    }

    $recordedMime = strtolower(trim((string) ($row['mime_type'] ?? '')));
    if ($recordedMime !== '' && !complaint_evidence_is_allowed_mime($recordedMime)) {
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = strtolower((string) $finfo->file($path));

    return complaint_evidence_is_allowed_mime($detected);
}

/**
 * @return array{
 *   id:int,
 *   media_type:string,
 *   mime_type:string,
 *   file_type_label:string,
 *   file_size_display:string,
 *   meta_line:string,
 *   view_url:string,
 *   original_filename:string,
 *   uploaded_label:string
 * }
 */
function complaint_evidence_row_to_item_meta(array $row): array
{
    $uploadedAt = (string) ($row['uploaded_at'] ?? '');
    $label = $uploadedAt !== '' ? date('M j, Y g:i A', strtotime($uploadedAt)) : '';
    $mime = (string) ($row['mime_type'] ?? '');
    $mediaType = (string) ($row['media_type'] ?? '');
    $originalFilename = (string) ($row['original_filename'] ?? '');
    $fileTypeLabel = complaint_evidence_format_file_type_label($mime, $mediaType);
    $fileSizeDisplay = complaint_evidence_format_file_size((int) ($row['file_size_bytes'] ?? 0));
    $metaParts = array_values(array_filter([
        $originalFilename !== '' ? $originalFilename : null,
        $fileTypeLabel !== '' ? $fileTypeLabel : null,
        $fileSizeDisplay !== '' ? $fileSizeDisplay : null,
        $label !== '' ? $label : null,
    ]));

    return [
        'id'                => (int) ($row['id'] ?? 0),
        'media_type'        => $mediaType,
        'mime_type'         => $mime,
        'file_type_label'   => $fileTypeLabel,
        'file_size_display' => $fileSizeDisplay,
        'meta_line'         => implode(' • ', $metaParts),
        'view_url'          => complaint_evidence_view_url((int) ($row['id'] ?? 0)),
        'original_filename' => $originalFilename,
        'uploaded_label'    => $label,
    ];
}

/**
 * @return array{
 *   has_evidence:bool,
 *   id:int,
 *   media_type:string,
 *   view_url:string,
 *   original_filename:string,
 *   uploaded_label:string,
 *   items:list<array<string, mixed>>
 * }
 */
function complaint_evidence_clinical_support_meta(PDO $pdo, int $triageId): array
{
    $empty = [
        'has_evidence'      => false,
        'id'                => 0,
        'media_type'        => '',
        'mime_type'         => '',
        'file_type_label'   => '',
        'file_size_display' => '',
        'meta_line'         => '',
        'view_url'          => '',
        'original_filename' => '',
        'uploaded_label'    => '',
        'items'             => [],
    ];

    $row = complaint_evidence_find_for_triage($pdo, $triageId);
    if ($row === null) {
        return $empty;
    }

    $item = complaint_evidence_row_to_item_meta($row);

    return array_merge($empty, $item, [
        'has_evidence' => true,
        'items'        => [$item],
    ]);
}

function complaint_evidence_can_view(PDO $pdo, array $row, int $userId, string $role): bool
{
    $patientId = (int) ($row['patient_id'] ?? 0);
    if ($patientId <= 0 || $userId <= 0) {
        return false;
    }

    if ($role === 'patient' && $userId === $patientId) {
        return true;
    }

    if ($role === 'provider') {
        require_once __DIR__ . '/provider_patient_access.php';
        $access = provider_patient_assert_access($pdo, $userId, $patientId);

        return !empty($access['allowed']);
    }

    if (in_array($role, ['admin', 'superadmin', 'bhw'], true)) {
        if ($role === 'bhw') {
            require_once dirname(__DIR__, 2) . '/resources/views/bhw/partials/bhw_context.php';
            require_once __DIR__ . '/bhw_scope.php';
            $ctx = bhw_resolve_context($pdo);
            if (empty($ctx['allowed'])) {
                return false;
            }

            return bhw_assert_patient_in_sector($pdo, $ctx, $patientId);
        }

        return true;
    }

    return false;
}

function complaint_evidence_strip_image_exif(string $path, string $mime): void
{
    if (!function_exists('imagecreatefromjpeg')) {
        return;
    }

    try {
        $image = match ($mime) {
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => @imagecreatefromjpeg($path),
        };
        if ($image === false) {
            return;
        }
        match ($mime) {
            'image/png'  => imagepng($image, $path),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $path, 85) : imagejpeg($image, $path, 90),
            default      => imagejpeg($image, $path, 90),
        };
        imagedestroy($image);
    } catch (Throwable $e) {
        // Non-fatal — original file remains.
    }
}

/**
 * Attach optional upload after triage is committed. Failures are logged, not thrown.
 *
 * @param array<string, mixed>|null $file
 */
function complaint_evidence_try_attach(PDO $pdo, int $patientId, int $triageId, ?array $file, int $consultationId = 0): void
{
    if ($file === null || !is_array($file)) {
        if ($consultationId > 0 && $triageId > 0) {
            complaint_evidence_link_consultation($pdo, $triageId, $consultationId);
        }
        return;
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($consultationId > 0 && $triageId > 0) {
            complaint_evidence_link_consultation($pdo, $triageId, $consultationId);
        }
        return;
    }
    if ($triageId <= 0) {
        return;
    }

    try {
        $result = complaint_evidence_save_for_triage($pdo, $patientId, $triageId, $file);
        if (!$result['success']) {
            error_log('complaint_evidence_try_attach: ' . ($result['message'] ?? 'unknown'));
        } elseif ($consultationId > 0) {
            complaint_evidence_link_consultation($pdo, $triageId, $consultationId);
        }
    } catch (Throwable $e) {
        error_log('complaint_evidence_try_attach: ' . $e->getMessage());
    }
}

function complaint_evidence_link_consultation(PDO $pdo, int $triageId, int $consultationId): void
{
    if ($triageId <= 0 || $consultationId <= 0) {
        return;
    }
    complaint_evidence_ensure_schema($pdo);
    try {
        $pdo->prepare('UPDATE complaint_evidence SET consultation_id = ? WHERE triage_result_id = ?')
            ->execute([$consultationId, $triageId]);
    } catch (PDOException $e) {
        error_log('complaint_evidence_link_consultation: ' . $e->getMessage());
    }
}
