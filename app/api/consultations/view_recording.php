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
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_recording_segments.php';

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

$segments = consultation_recording_segments_list($pdo, $consultationId);
$legacyRel = consultation_video_recording_public_path(
    (string) ($row['recording_path'] ?? ''),
    (string) ($row['recording_url'] ?? '')
);
if ($segments === [] && $legacyRel !== '') {
    $segments[] = [
        'id' => 0,
        'segment_index' => 1,
        'recording_path' => $legacyRel,
        'status' => 'saved',
        'started_at' => (string) ($row['started_at'] ?? ''),
        'ended_at' => (string) ($row['ended_at'] ?? ''),
        'started_label' => '',
        'ended_label' => '',
        'duration_label' => '',
        'playable' => true,
    ];
}

$resolveAbs = static function (string $rel) {
    $recordingsDir = realpath(STORAGE_PATH . DIRECTORY_SEPARATOR . 'recordings');
    $abs = $rel !== '' ? realpath(BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel)) : false;
    if ($abs === false || $recordingsDir === false || !str_starts_with($abs, $recordingsDir) || !is_file($abs)) {
        return false;
    }
    return $abs;
};

$requestedSegmentId = (int) ($_GET['segment_id'] ?? 0);
$active = null;
if ($requestedSegmentId > 0) {
    foreach ($segments as $segment) {
        if ((int) ($segment['id'] ?? 0) === $requestedSegmentId) {
            $active = $segment;
            break;
        }
    }
}
if ($active === null) {
    foreach ($segments as $segment) {
        if (!empty($segment['playable'])) {
            $active = $segment;
            break;
        }
    }
}

$rel = is_array($active) ? (string) ($active['recording_path'] ?? '') : '';
$abs = $rel !== '' ? $resolveAbs($rel) : false;
$playable = $abs !== false && is_array($active) && !empty($active['playable']);

$ext = $playable ? strtolower((string) pathinfo((string) $abs, PATHINFO_EXTENSION)) : '';
$mime = match ($ext) {
    'webm' => 'video/webm',
    'mp4' => 'video/mp4',
    'ogg', 'ogv' => 'video/ogg',
    default => 'application/octet-stream',
};

$stream = isset($_GET['stream']) && (string) $_GET['stream'] === '1';
if ($stream) {
    if (!$playable) {
        http_response_code(404);
        echo 'Recording segment not available.';
        exit;
    }
    consultation_stream_recording_file((string) $abs, $mime);
    exit;
}

$streamUrl = '';
if ($playable) {
    $streamUrl = ASSET_BASE . '/app/api/consultations/view_recording.php?consultation_id=' . $consultationId . '&stream=1';
    if ((int) ($active['id'] ?? 0) > 0) {
        $streamUrl .= '&segment_id=' . (int) $active['id'];
    }
}
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
  <meta name="color-scheme" content="light">
  <meta name="theme-color" content="#0097A7">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($logoUrl) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/design-system.css">
  <style>
    /* Recording viewer — MedConnect theme (layout/visual only) */
    * { box-sizing: border-box; }
    html, body {
      margin: 0;
      min-height: 100%;
    }
    body.recording-viewer {
      background: var(--mc-ice-blue, #F3F8FB);
      color: var(--mc-navy-dark, #0D2137);
      font-family: var(--mc-font, Inter, system-ui, -apple-system, sans-serif);
      font-size: var(--mc-fs-body, 0.875rem);
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
    }
    .rv-top {
      position: sticky;
      top: 0;
      z-index: 40;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      min-height: 56px;
      padding: calc(10px + var(--mc-safe-top, env(safe-area-inset-top, 0px))) 20px 10px;
      background: var(--mc-white, #fff);
      border-bottom: 1px solid var(--mc-border-thin, #DDE8EE);
      box-shadow: 0 1px 0 rgba(13, 33, 55, 0.04), 0 2px 12px rgba(13, 33, 55, 0.04);
    }
    .rv-brand {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
      color: var(--mc-navy-dark, #0D2137);
      font-weight: 700;
      font-size: 15px;
      letter-spacing: -0.01em;
    }
    .rv-brand__mark {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      border-radius: 12px;
      background: var(--mc-aqua-light, #E0F7FA);
      flex-shrink: 0;
    }
    .rv-brand__mark img {
      width: 22px;
      height: 22px;
      object-fit: contain;
      display: block;
    }
    .rv-brand__name span {
      color: var(--mc-aqua, #0097A7);
    }
    .rv-back {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      min-height: 40px;
      padding: 8px 12px;
      border-radius: var(--mc-radius-sm, 8px);
      color: var(--mc-aqua, #0097A7);
      text-decoration: none;
      font-weight: 600;
      font-size: 14px;
      border: 1px solid transparent;
      transition: background 0.15s ease, border-color 0.15s ease;
    }
    .rv-back:hover {
      background: var(--mc-aqua-light, #E0F7FA);
      border-color: rgba(0, 151, 167, 0.25);
    }
    .rv-page {
      width: 100%;
      max-width: 1120px;
      margin: 0 auto;
      padding: 14px 20px calc(20px + var(--mc-safe-bottom, env(safe-area-inset-bottom, 0px)));
    }
    .rv-card {
      background: var(--mc-white, #fff);
      border: 1px solid var(--mc-border-thin, #DDE8EE);
      border-radius: var(--mc-radius, 14px);
      box-shadow: var(--mc-shadow-card, 0 4px 20px rgba(13, 33, 55, 0.08));
      overflow: hidden;
    }
    .rv-card + .rv-card {
      margin-top: 12px;
    }
    /* Cap height so video + consultation card fit a laptop viewport */
    .rv-stage {
      position: relative;
      background: #0B1220;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      aspect-ratio: 16 / 9;
      max-height: min(520px, calc(100dvh - 270px));
    }
    .rv-stage video {
      display: block;
      width: 100%;
      height: 100%;
      max-height: 100%;
      object-fit: contain;
      background: #0B1220;
      vertical-align: middle;
    }
    .rv-play-fab {
      position: absolute;
      inset: 0;
      margin: auto;
      width: 68px;
      height: 68px;
      border: 0;
      border-radius: 50%;
      background: var(--mc-aqua, #0097A7);
      color: #fff;
      cursor: pointer;
      display: grid;
      place-items: center;
      padding: 0;
      box-shadow: 0 8px 24px rgba(0, 110, 123, 0.35);
      transition: background 0.15s ease, transform 0.15s ease;
    }
    .rv-play-fab:hover {
      background: var(--mc-aqua-dark, #006E7B);
      transform: scale(1.04);
    }
    .rv-play-fab[hidden] { display: none; }
    .rv-play-fab svg { display: block; margin-left: 3px; }
    .rv-empty {
      margin: 0;
      padding: 48px 24px;
      text-align: center;
      color: #94a3b8;
      font-size: 14px;
    }
    .rv-sheet {
      padding: 16px 20px 18px;
    }
    .rv-eyebrow {
      margin: 0 0 6px;
      font-size: 11.5px;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--mc-aqua, #0097A7);
    }
    .rv-title {
      margin: 0 0 4px;
      font-size: 1.15rem;
      line-height: 1.3;
      font-weight: 800;
      color: var(--mc-navy-dark, #0D2137);
      letter-spacing: -0.02em;
    }
    .rv-people {
      margin: 0 0 12px;
      font-size: 14px;
      font-weight: 600;
      color: var(--mc-navy-dark, #0D2137);
    }
    .rv-people span {
      color: var(--mc-slate-muted, #5B7A8D);
      font-weight: 500;
    }
    .rv-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin: 0 0 12px;
    }
    .rv-chip {
      display: inline-flex;
      align-items: center;
      min-height: 28px;
      padding: 4px 11px;
      border-radius: 999px;
      background: var(--mc-aqua-light, #E0F7FA);
      border: 1px solid rgba(0, 151, 167, 0.22);
      color: var(--mc-aqua-dark, #006E7B);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.01em;
    }
    .rv-chip--muted {
      background: var(--mc-ice-blue, #F3F8FB);
      border-color: var(--mc-border-thin, #DDE8EE);
      color: var(--mc-slate-muted, #5B7A8D);
    }
    .rv-hint {
      margin: 0;
      font-size: 13px;
      color: var(--mc-slate-muted, #5B7A8D);
      line-height: 1.45;
    }
    .rv-segments {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin: 0 0 12px;
    }
    .rv-seg {
      display: inline-flex;
      flex-direction: column;
      align-items: flex-start;
      min-height: 44px;
      padding: 8px 12px;
      border-radius: var(--mc-radius-sm, 8px);
      border: 1px solid var(--mc-border-thin, #DDE8EE);
      background: var(--mc-ice-blue, #F3F8FB);
      color: var(--mc-navy-dark, #0D2137);
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      transition: border-color 0.15s ease, background 0.15s ease;
    }
    .rv-seg small {
      font-weight: 500;
      color: var(--mc-slate-muted, #5B7A8D);
    }
    .rv-seg:hover {
      border-color: rgba(0, 151, 167, 0.45);
      background: var(--mc-aqua-light, #E0F7FA);
    }
    .rv-seg.is-active {
      border-color: var(--mc-aqua, #0097A7);
      background: var(--mc-aqua-light, #E0F7FA);
      color: var(--mc-aqua-dark, #006E7B);
      box-shadow: 0 0 0 2px rgba(0, 151, 167, 0.12);
    }
    .rv-seg.is-disabled {
      opacity: 0.55;
      pointer-events: none;
    }
    @media (max-width: 640px) {
      .rv-top { padding-left: 14px; padding-right: 14px; }
      .rv-page { padding: 12px 12px calc(20px + var(--mc-safe-bottom, env(safe-area-inset-bottom, 0px))); }
      .rv-card + .rv-card { margin-top: 10px; }
      .rv-stage {
        aspect-ratio: auto;
        min-height: 200px;
        max-height: min(48dvh, 360px);
      }
      .rv-stage video { max-height: 100%; }
      .rv-sheet { padding: 14px 14px 16px; }
      .rv-title { font-size: 1.05rem; }
      .rv-brand__name { font-size: 14px; }
    }
    @media (min-width: 768px) {
      .rv-page { padding-top: 16px; }
      .rv-sheet { padding: 16px 22px 18px; }
      .rv-stage {
        max-height: min(500px, calc(100dvh - 260px));
      }
    }
    @media (min-width: 1100px) {
      .rv-page { max-width: 1180px; }
      .rv-stage {
        max-height: min(520px, calc(100dvh - 250px));
      }
    }
    /* Short laptop viewports: keep consultation info in view */
    @media (min-width: 641px) and (max-height: 800px) {
      .rv-page { padding-top: 12px; padding-bottom: calc(14px + var(--mc-safe-bottom, env(safe-area-inset-bottom, 0px))); }
      .rv-stage {
        max-height: min(420px, calc(100dvh - 240px));
      }
      .rv-sheet { padding-top: 14px; padding-bottom: 14px; }
    }
  </style>
</head>
<body class="recording-viewer">
  <header class="rv-top">
    <a class="rv-back" href="<?= htmlspecialchars($backUrl) ?>">← Back to consultation</a>
    <a class="rv-brand" href="<?= htmlspecialchars($backUrl) ?>">
      <span class="rv-brand__mark"><img src="<?= htmlspecialchars($logoUrl) ?>" alt=""></span>
      <span class="rv-brand__name">med<span>Connect</span></span>
    </a>
  </header>
  <main class="rv-page">
    <section class="rv-card" aria-label="Video recording">
      <div class="rv-stage">
        <?php if ($playable && $streamUrl !== ''): ?>
        <video id="recVideo" controls playsinline preload="metadata" src="<?= htmlspecialchars($streamUrl) ?>">
          Your browser cannot play this recording.
        </video>
        <button type="button" class="rv-play-fab" id="recPlayFab" aria-label="Play recording">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
        </button>
        <?php else: ?>
        <p class="rv-empty">No playable recording file is available for this consultation.</p>
        <?php endif; ?>
      </div>
    </section>

    <section class="rv-card rv-sheet" aria-label="Consultation information">
      <p class="rv-eyebrow">Consultation Information</p>
      <h1 class="rv-title">Video consultation recording</h1>
      <p class="rv-people"><?= htmlspecialchars($patientName) ?> <span>· <?= htmlspecialchars($providerName) ?></span></p>
      <div class="rv-chips">
        <span class="rv-chip">Consult #<?= (int) $consultationId ?></span>
        <?php if ($dateLabel !== ''): ?><span class="rv-chip rv-chip--muted"><?= htmlspecialchars($dateLabel) ?></span><?php endif; ?>
        <?php if ($durationLabel !== ''): ?><span class="rv-chip rv-chip--muted"><?= htmlspecialchars($durationLabel) ?></span><?php endif; ?>
      </div>
      <?php if (count($segments) > 1): ?>
      <div class="rv-segments">
        <?php foreach ($segments as $segment):
          $sid = (int) ($segment['id'] ?? 0);
          $idx = (int) ($segment['segment_index'] ?? 0);
          $canPlay = !empty($segment['playable']);
          $isActive = $active && (int) ($active['id'] ?? 0) === $sid;
          $href = ASSET_BASE . '/app/api/consultations/view_recording.php?consultation_id=' . $consultationId
            . ($sid > 0 ? '&segment_id=' . $sid : '');
          $timeBits = trim((string) ($segment['started_label'] ?? '') . ((string) ($segment['ended_label'] ?? '') !== '' ? '–' . $segment['ended_label'] : ''));
          $statusBit = $canPlay ? ($timeBits !== '' ? $timeBits : 'Ready') : ucfirst((string) ($segment['status'] ?? 'unavailable'));
        ?>
        <?php if ($canPlay): ?>
        <a class="rv-seg<?= $isActive ? ' is-active' : '' ?>" href="<?= htmlspecialchars($href) ?>">Segment <?= $idx ?: 1 ?><small><?= htmlspecialchars($statusBit) ?></small></a>
        <?php else: ?>
        <span class="rv-seg is-disabled">Segment <?= $idx ?: 1 ?><small><?= htmlspecialchars($statusBit) ?></small></span>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <p class="rv-hint"><?php
        if (!$playable) {
            echo 'A recording was not saved, or the file is missing from storage.';
        } elseif (count($segments) > 1) {
            echo 'This visit has more than one saved segment. Choose a segment above, then use the player controls.';
        } else {
            echo 'Tap the player to play, pause, or enter fullscreen.';
        }
      ?></p>
    </section>
  </main>
  <?php if ($playable && $streamUrl !== ''): ?>
  <script>
    (function () {
      var video = document.getElementById('recVideo');
      var fab = document.getElementById('recPlayFab');
      if (!video || !fab) return;
      function sync() {
        fab.hidden = !video.paused;
      }
      fab.addEventListener('click', function () {
        video.play().catch(function () {});
      });
      video.addEventListener('play', sync);
      video.addEventListener('pause', sync);
      video.addEventListener('ended', sync);
      sync();
    })();
  </script>
  <?php endif; ?>
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
