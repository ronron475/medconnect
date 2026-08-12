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
    SELECT c.id, c.patient_id, c.provider_id, c.status, c.consult_date, c.consult_time,
           vs.recording_path, vs.recording_url, vs.started_at, vs.ended_at,
           TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS patient_name,
           TRIM(CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, ''))) AS provider_name
    FROM consultations c
    LEFT JOIN users p ON p.id = c.patient_id
    LEFT JOIN users d ON d.id = c.provider_id
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
        SELECT c.id, c.patient_id, c.provider_id, c.status, c.consult_date, c.consult_time,
               vs.recording_path, NULL AS recording_url, vs.started_at, vs.ended_at,
               TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS patient_name,
               TRIM(CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, ''))) AS provider_name
        FROM consultations c
        LEFT JOIN users p ON p.id = c.patient_id
        LEFT JOIN users d ON d.id = c.provider_id
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
    echo 'Access denied.';
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

$streamUrl = ASSET_BASE . '/app/api/consultations/view_recording.php?consultation_id=' . $consultationId . '&stream=1';
$patientName = trim((string) ($row['patient_name'] ?? '')) ?: 'Patient';
$providerName = trim((string) ($row['provider_name'] ?? '')) ?: 'Provider';
if (!preg_match('/^dr\.?\s/i', $providerName)) {
    $providerName = 'Dr. ' . $providerName;
}
$durationLabel = consultation_format_video_duration(
    (string) ($row['started_at'] ?? ''),
    (string) ($row['ended_at'] ?? '')
);
$dateLabel = '';
if (!empty($row['started_at']) && strtotime((string) $row['started_at'])) {
    $dateLabel = date('M j, Y — g:i A', strtotime((string) $row['started_at']));
} elseif (!empty($row['consult_date'])) {
    $dateLabel = date('M j, Y', strtotime((string) $row['consult_date']));
    if (!empty($row['consult_time'])) {
        $dateLabel .= ' — ' . date('g:i A', strtotime((string) $row['consult_time']));
    }
}
$backUrl = $role === 'patient'
    ? ASSET_BASE . '/views/patient/consultation_detail.php?id=' . $consultationId . '&from=sessions'
    : ASSET_BASE . '/views/provider/consultation_history.php?patient_id=' . (int) ($row['patient_id'] ?? 0);
$logoUrl = ASSET_BASE . '/assets/img/medcon_logo.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Consultation #<?= (int) $consultationId ?> recording — medConnect</title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($logoUrl) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --ice: #f4f8fa;
      --navy: #012a4a;
      --aqua: #018a93;
      --slate: #608395;
      --line: #e2edf1;
      --white: #fff;
      --safe-top: env(safe-area-inset-top, 0px);
      --safe-bottom: env(safe-area-inset-bottom, 0px);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: Inter, system-ui, sans-serif;
      background: var(--ice);
      color: var(--navy);
    }
    .top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: calc(14px + var(--safe-top)) 20px 14px;
      background: var(--white);
      border-bottom: 1px solid var(--line);
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 700;
      color: var(--navy);
      text-decoration: none;
    }
    .brand img { width: 32px; height: 32px; }
    .back {
      display: inline-flex;
      align-items: center;
      min-height: 44px;
      padding: 8px 12px;
      border-radius: 10px;
      color: var(--aqua);
      text-decoration: none;
      font-weight: 600;
      font-size: 14px;
    }
    .back:hover { background: #d8eaee; }
    .page {
      max-width: 980px;
      margin: 0 auto;
      padding: 24px 16px calc(32px + var(--safe-bottom));
    }
    .card {
      background: var(--white);
      border: 1px solid var(--line);
      border-radius: 16px;
      box-shadow: 0 1px 3px rgba(1, 42, 74, 0.06);
      overflow: hidden;
    }
    .card__head {
      padding: 20px 20px 16px;
    }
    .eyebrow {
      margin: 0 0 4px;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--aqua);
    }
    h1 {
      margin: 0 0 14px;
      font-size: 1.35rem;
      line-height: 1.3;
    }
    .meta {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px 16px;
      margin: 0;
    }
    .meta div { min-width: 0; }
    .meta dt {
      margin: 0;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--slate);
    }
    .meta dd {
      margin: 2px 0 0;
      font-size: 14px;
      font-weight: 600;
      word-break: break-word;
    }
    .stage {
      background: #0b1220;
      aspect-ratio: 16 / 9;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    video {
      width: 100%;
      height: 100%;
      max-height: min(70vh, 720px);
      object-fit: contain;
      background: #0b1220;
      vertical-align: middle;
    }
    .hint {
      margin: 0;
      padding: 12px 20px 18px;
      font-size: 13px;
      color: var(--slate);
      line-height: 1.45;
    }
    @media (max-width: 640px) {
      .meta { grid-template-columns: 1fr; }
      .page { padding: 16px 12px calc(24px + var(--safe-bottom)); }
      h1 { font-size: 1.15rem; }
      .stage { aspect-ratio: auto; min-height: 220px; }
      video { max-height: 62vh; }
    }
  </style>
</head>
<body>
  <header class="top">
    <a class="back" href="<?= htmlspecialchars($backUrl) ?>">← Back to consultation</a>
    <a class="brand" href="<?= htmlspecialchars($backUrl) ?>">
      <img src="<?= htmlspecialchars($logoUrl) ?>" alt="">
      medConnect
    </a>
  </header>
  <main class="page">
    <article class="card">
      <div class="card__head">
        <p class="eyebrow">Recorded visit</p>
        <h1>Video consultation recording</h1>
        <dl class="meta">
          <div><dt>Consultation</dt><dd>#<?= (int) $consultationId ?></dd></div>
          <div><dt>Date</dt><dd><?= htmlspecialchars($dateLabel !== '' ? $dateLabel : '—') ?></dd></div>
          <div><dt>Patient</dt><dd><?= htmlspecialchars($patientName) ?></dd></div>
          <div><dt>Provider</dt><dd><?= htmlspecialchars($providerName) ?></dd></div>
          <?php if ($durationLabel !== ''): ?>
          <div><dt>Duration</dt><dd><?= htmlspecialchars($durationLabel) ?></dd></div>
          <?php endif; ?>
        </dl>
      </div>
      <div class="stage">
        <video controls playsinline preload="metadata" src="<?= htmlspecialchars($streamUrl) ?>">
          Your browser cannot play this recording.
        </video>
      </div>
      <p class="hint">This is the saved consultation recording. Use the player controls to play, pause, or enter fullscreen.</p>
    </article>
  </main>
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
