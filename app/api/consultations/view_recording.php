<?php
/**
 * Play a stored consultation recording with the correct video MIME type.
 * Direct /storage/recordings/*.webm links are served as text on Hostinger
 * (unknown MIME + X-Content-Type-Options: nosniff), so Chrome dumps binary.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_video_history.php';

$uid = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['user_role'] ?? '');
if ($uid <= 0 || $role === '') {
    header('Location: ' . auth_signin_required_url());
    exit;
}

$consultationId = (int) ($_GET['consultation_id'] ?? 0);
if ($consultationId <= 0) {
    http_response_code(400);
    echo 'Consultation ID is required.';
    exit;
}

clinical_tables_ensure($pdo);

$stmt = $pdo->prepare("
    SELECT c.id, c.patient_id, c.provider_id, c.status,
           vs.recording_path, vs.recording_url, vs.started_at, vs.ended_at
    FROM consultations c
    LEFT JOIN video_sessions vs ON vs.id = (
        SELECT vs2.id
        FROM video_sessions vs2
        WHERE vs2.consultation_id = c.id
        ORDER BY vs2.id DESC
        LIMIT 1
    )
    WHERE c.id = ?
    LIMIT 1
");
try {
    $stmt->execute([$consultationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.patient_id, c.provider_id, c.status,
               vs.recording_path, NULL AS recording_url, vs.started_at, vs.ended_at
        FROM consultations c
        LEFT JOIN video_sessions vs ON vs.consultation_id = c.id
        WHERE c.id = ?
        ORDER BY vs.id DESC
        LIMIT 1
    ");
    $stmt->execute([$consultationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$row) {
    http_response_code(404);
    echo 'Consultation not found.';
    exit;
}

$allowed = ($role === 'provider' && $uid === (int) ($row['provider_id'] ?? 0))
    || ($role === 'patient' && $uid === (int) ($row['patient_id'] ?? 0));
if (!$allowed) {
    http_response_code(403);
    echo 'You are not authorized to view this recording.';
    exit;
}

$rel = consultation_video_recording_public_path(
    (string) ($row['recording_path'] ?? ''),
    (string) ($row['recording_url'] ?? '')
);
$recordingsDir = realpath(STORAGE_PATH . DIRECTORY_SEPARATOR . 'recordings');
$abs = $rel !== '' ? realpath(BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel)) : false;
if ($rel === '' || $abs === false || $recordingsDir === false || !str_starts_with($abs, $recordingsDir) || !is_file($abs)) {
    http_response_code(404);
    echo 'Video recording not available for this consultation.';
    exit;
}

$ext = strtolower((string) pathinfo($abs, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'webm' => 'video/webm',
    'mp4' => 'video/mp4',
    'ogg', 'ogv' => 'video/ogg',
    default => 'application/octet-stream',
};

$stream = isset($_GET['stream']) && (string) $_GET['stream'] === '1';
if ($stream) {
    consultation_stream_recording_file($abs, $mime);
    exit;
}

$streamUrl = consultation_video_recording_view_url($consultationId) . '&stream=1';
$dateLabel = '';
if (!empty($row['started_at']) && strtotime((string) $row['started_at'])) {
    $dateLabel = date('M j, Y g:i A', strtotime((string) $row['started_at']));
}
$backUrl = $role === 'patient'
    ? ASSET_BASE . '/views/patient/consultation_detail.php?id=' . $consultationId
    : ASSET_BASE . '/views/provider/consultation_session.php?id=' . $consultationId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Consultation recording — medConnect</title>
  <style>
    body { margin: 0; font-family: system-ui, sans-serif; background: #0b1220; color: #e2e8f0; }
    .wrap { max-width: 960px; margin: 0 auto; padding: 20px 16px 32px; }
    a { color: #5eead4; }
    h1 { font-size: 1.15rem; margin: 0 0 6px; }
    .meta { color: #94a3b8; font-size: 0.9rem; margin: 0 0 16px; }
    video { width: 100%; max-height: 70vh; background: #000; border-radius: 12px; }
  </style>
</head>
<body>
  <div class="wrap">
    <p><a href="<?= htmlspecialchars($backUrl) ?>">← Back to consultation</a></p>
    <h1>Video consultation recording</h1>
    <p class="meta">Consultation #<?= (int) $consultationId ?><?= $dateLabel !== '' ? ' · ' . htmlspecialchars($dateLabel) : '' ?></p>
    <video controls playsinline preload="metadata" src="<?= htmlspecialchars($streamUrl) ?>">
      Your browser cannot play this recording.
    </video>
  </div>
</body>
</html>
<?php

function consultation_stream_recording_file(string $path, string $mime): void
{
    $size = (int) filesize($path);
    $start = 0;
    $end = max(0, $size - 1);
    $code = 200;

    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
        if ($m[1] !== '') {
            $start = (int) $m[1];
        }
        if ($m[2] !== '') {
            $end = (int) $m[2];
        }
        if ($end >= $size) {
            $end = $size - 1;
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $code = 206;
    }

    http_response_code($code);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    $length = $end - $start + 1;
    header('Content-Length: ' . $length);
    if ($code === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit;
    }
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        if (function_exists('flush')) {
            flush();
        }
    }
    fclose($fp);
    exit;
}
