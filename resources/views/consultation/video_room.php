<?php
// Avoid holding a write-lock on the PHP session for this long-lived page
// (provider + patient can open video rooms without session lock deadlocks).
if (!defined('MEDCONNECT_SESSION_READ_AND_CLOSE')) {
    define('MEDCONNECT_SESSION_READ_AND_CLOSE', true);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'read_and_close' => true,
    ]);
}

if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}

$token = $_GET['token'] ?? '';
$role  = $_SESSION['user_role'] ?? '';
$uid   = $_SESSION['user_id'] ?? 0;
$pageCsrfToken = (string) ($_SESSION['csrf_token'] ?? '');

// Ensure bootstrap is loaded; if it re-opened a writeable session, release immediately.
if (session_status() === PHP_SESSION_ACTIVE) {
    if ($pageCsrfToken === '' && !empty($_SESSION['csrf_token'])) {
        $pageCsrfToken = (string) $_SESSION['csrf_token'];
    }
    if ($role === '' && !empty($_SESSION['user_role'])) {
        $role = (string) $_SESSION['user_role'];
    }
    if (!$uid && !empty($_SESSION['user_id'])) {
        $uid = (int) $_SESSION['user_id'];
    }
    session_write_close();
}

if (!$token || !$role || !$uid) {
    require_once BASE_PATH . '/app/includes/auth_guard.php';
    header('Location: ' . auth_signin_required_url());
    exit;
}

$stmt = $pdo->prepare("
    SELECT vs.*, c.patient_id, c.provider_id, c.consult_date, c.consult_time, c.status AS consult_status,
           p.first_name as patient_first, p.last_name as patient_last,
           d.first_name as doctor_first, d.last_name as doctor_last,
           pp.specialty as provider_specialty,
           s.id AS slot_id, s.slot_date, s.start_time AS slot_start, s.end_time AS slot_end
    FROM video_sessions vs
    JOIN consultations c ON vs.consultation_id = c.id
    LEFT JOIN users p ON c.patient_id = p.id
    LEFT JOIN users d ON c.provider_id = d.id
    LEFT JOIN provider_profiles pp ON pp.user_id = c.provider_id
    LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
    WHERE vs.room_token = ? AND vs.status = 'active' LIMIT 1
");
$stmt->execute([$token]);
$session = $stmt->fetch();

if (!$session) {
    die('Invalid or expired consultation link.');
}

$authorized = false;
if ($role === 'patient' && (int) $uid === (int) $session['patient_id']) {
    $authorized = true;
} elseif ($role === 'provider' && (int) $uid === (int) $session['provider_id']) {
    $authorized = true;
} elseif ($role === 'bhw') {
    require_once VIEWS_PATH . '/bhw/partials/bhw_context.php';
    require_once BASE_PATH . '/app/includes/bhw_scope.php';
    $bhw_ctx = bhw_resolve_context($pdo);
    if ($bhw_ctx['allowed'] && bhw_assert_patient_in_sector($pdo, $bhw_ctx, (int) $session['patient_id'])) {
        $authorized = true;
    }
}
if (!$authorized) {
    die('You are not authorized to join this consultation.');
}

require_once dirname(__DIR__) . '/provider/partials/queue_helpers.php';
$video_access = consultation_video_room_access([
    'status'       => $session['consult_status'] ?? '',
    'consult_date' => $session['consult_date'] ?? '',
    'consult_time' => $session['consult_time'] ?? '',
    'slot_date'    => $session['slot_date'] ?? '',
    'slot_start'   => $session['slot_start'] ?? '',
]);
if (!$video_access['allowed']) {
    http_response_code(403);
    die(htmlspecialchars($video_access['reason']));
}

$patient_name = trim(($session['patient_first'] ?? '') . ' ' . ($session['patient_last'] ?? ''));
$provider_name = trim(($session['doctor_first'] ?? '') . ' ' . ($session['doctor_last'] ?? ''));
$provider_specialty = trim((string) ($session['provider_specialty'] ?? ''));
if ($provider_specialty === '') {
    $provider_specialty = 'General Medicine';
}
$patient_initials = strtoupper(substr($session['patient_first'] ?? 'P', 0, 1) . substr($session['patient_last'] ?? 'T', 0, 1));
$provider_initials = strtoupper(substr($session['doctor_first'] ?? 'H', 0, 1) . substr($session['doctor_last'] ?? 'P', 0, 1));
$other_name = ($role === 'provider') ? $patient_name : $provider_name;

$slot_minutes = 30;
$seconds_remaining = $slot_minutes * 60;
$slot_end_label = '';
if (!empty($session['slot_start']) && !empty($session['slot_end'])) {
    $start_ts = strtotime((string) $session['slot_start']);
    $end_ts = strtotime((string) $session['slot_end']);
    if ($start_ts && $end_ts && $end_ts > $start_ts) {
        $slot_minutes = max(15, (int) round(($end_ts - $start_ts) / 60));
    }
}
$slot_date = $session['slot_date'] ?? $session['consult_date'] ?? date('Y-m-d');
if (!empty($session['slot_end'])) {
    $slot_end_ts = strtotime($slot_date . ' ' . $session['slot_end']);
    if ($slot_end_ts) {
        $seconds_remaining = max(0, $slot_end_ts - time());
        $slot_end_label = date('g:i A', $slot_end_ts);
    }
} elseif (!empty($session['consult_time'])) {
    $slot_end_ts = strtotime($slot_date . ' ' . $session['consult_time']) + ($slot_minutes * 60);
    $seconds_remaining = max(0, $slot_end_ts - time());
    $slot_end_label = date('g:i A', $slot_end_ts);
}
$is_patient = ($role === 'patient');
$consultation_id = (int) ($session['consultation_id'] ?? 0);

// Persist a real CSRF token so mute-TTS / messages APIs work in production.
// Never invent a page-only token â€” it would fail auth_csrf_validate on send.php.
if ($pageCsrfToken === '' && !empty($_SESSION['csrf_token'])) {
    $pageCsrfToken = (string) $_SESSION['csrf_token'];
}
if ($pageCsrfToken === '' || empty($_SESSION['csrf_token'])
    || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $pageCsrfToken)) {
    $hadActiveSession = session_status() === PHP_SESSION_ACTIVE;
    if (!$hadActiveSession && session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (session_status() === PHP_SESSION_ACTIVE || !empty($_SESSION)) {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $pageCsrfToken = (string) $_SESSION['csrf_token'];
    }
    if (!$hadActiveSession && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}
if ($pageCsrfToken === '') {
    // Demo without login: page token unused by demo mute API; keep a local placeholder.
    $pageCsrfToken = bin2hex(random_bytes(16));
}

// Keep session unlocked for the whole HTML response (second Chrome tab must be able to load).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <?php require_once VIEWS_PATH . '/partials/theme_init.php'; ?>
  <meta http-equiv="Permissions-Policy" content="camera=(self), microphone=(self), display-capture=(self)"/>
  <title>Video Consultation â€” medConnect</title>
  <?php require_once __DIR__ . '/../../bootstrap.php'; ?>
  <?php
  require_once VIEWS_PATH . '/components/global-loader.php';
  $glCssVer = mc_global_loader_asset_ver('css/global-loader.css');
  $glJsVer  = mc_global_loader_asset_ver('js/global-loader.js');
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/global-loader.css?v=<?= $glCssVer ?>"/>
  <script src="<?= ASSET_BASE ?>/assets/js/global-loader.js?v=<?= $glJsVer ?>"></script>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/responsive.css"/>
  <script src="https://unpkg.com/peerjs@1.5.2/dist/peerjs.min.js"></script>
  <?php
  $muteTtsCssVer = (int) @filemtime(ASSETS_PATH . '/css/video-mute-tts.css');
  $muteTtsJsVer = (int) @filemtime(ASSETS_PATH . '/js/video-mute-tts.js');
  $videoCoreJsVer = (int) @filemtime(ASSETS_PATH . '/js/video-call-core.js');
  $webrtcPeerJsVer = (int) @filemtime(ASSETS_PATH . '/js/webrtc-peer-call.js');
  $videoUiCssVer = (int) @filemtime(ASSETS_PATH . '/css/video-consultation-ui.css');
  $videoUiJsVer = (int) @filemtime(ASSETS_PATH . '/js/video-consultation-ui.js');
  $videoEnhCssVer = (int) @filemtime(ASSETS_PATH . '/css/video-room-enhancements.css');
  $videoEnhJsVer = (int) @filemtime(ASSETS_PATH . '/js/video-room-enhancements.js');
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/video-mute-tts.css?v=<?= $muteTtsCssVer ?>"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/video-consultation-ui.css?v=<?= $videoUiCssVer ?>"/>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/video-room-enhancements.css?v=<?= $videoEnhCssVer ?>"/>
  <script src="<?= ASSET_BASE ?>/assets/js/video-call-core.js?v=<?= $videoCoreJsVer ?>"></script>
  <script src="<?= ASSET_BASE ?>/assets/js/webrtc-peer-call.js?v=<?= $webrtcPeerJsVer ?>"></script>
  <script src="<?= ASSET_BASE ?>/assets/js/video-consultation-ui.js?v=<?= $videoUiJsVer ?>"></script>
  <script src="<?= ASSET_BASE ?>/assets/js/video-mute-tts.js?v=<?= $muteTtsJsVer ?>"></script>
  <script>
    window.__mcVideoRoomMeta = {
      apiBase: <?= json_encode((string) ASSET_BASE) ?>,
      roomToken: <?= json_encode($token) ?>,
      consultationId: <?= (int) $consultation_id ?>,
      patientId: <?= (int) ($session['patient_id'] ?? 0) ?>,
      isPatient: <?= $is_patient ? 'true' : 'false' ?>,
      csrf: <?= json_encode($pageCsrfToken) ?>,
    };
  </script>
  <script src="<?= ASSET_BASE ?>/assets/js/video-room-enhancements.js?v=<?= $videoEnhJsVer ?>"></script>
  <style>
    body { margin:0; background:#0b1220; color:#fff; height:100vh; overflow:hidden; }
    body:not(.media-ready) .mc-vc-controls { display: none !important; }
    body:not(.media-ready) .mc-vc-header,
    body:not(.media-ready) .mc-vc-status-bar,
    body:not(.media-ready) .mc-vc-panel-toggle { display: none !important; }
    .end-modal {
      position: fixed;
      inset: 0;
      z-index: 100100;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(2, 6, 23, 0.72);
      backdrop-filter: blur(6px);
    }
    .end-modal.show { display: flex; }
    .end-dialog {
      width: min(420px, 100%);
      background: #0f172a;
      border: 1px solid rgba(148, 163, 184, 0.25);
      border-radius: 18px;
      padding: 24px;
      box-shadow: 0 24px 70px rgba(0,0,0,.45);
      color: #fff;
      text-align: center;
    }
    .end-icon {
      width: 58px;
      height: 58px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px;
      background: rgba(239, 68, 68, 0.12);
      color: #f87171;
      border: 1px solid rgba(248, 113, 113, 0.25);
    }
    .end-title { font-size: 18px; font-weight: 800; margin-bottom: 8px; }
    .end-copy { color: #cbd5e1; font-size: 13.5px; line-height: 1.55; margin-bottom: 20px; }
    .end-actions { display: flex; justify-content: center; gap: 10px; }
    .end-actions button {
      height: 42px;
      border-radius: 10px;
      border: 1px solid rgba(148, 163, 184, 0.25);
      padding: 0 18px;
      font-weight: 800;
      cursor: pointer;
    }
    .end-actions .keep { background: #1e293b; color: #fff; }
    .end-actions .confirm { background: #dc2626; color: #fff; border-color: #dc2626; }
    .end-actions button:disabled { opacity: .6; cursor: not-allowed; }
    .mc-vc-btn--report {
      font-size: 12px;
      font-weight: 800;
      padding: 0 12px;
      min-width: auto;
      background: rgba(251, 191, 36, 0.12);
      color: #fbbf24;
      border: 1px solid rgba(251, 191, 36, 0.35);
    }
    .violation-modal {
      position: fixed;
      inset: 0;
      z-index: 100090;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(2, 6, 23, 0.72);
      backdrop-filter: blur(6px);
    }
    .violation-modal.show { display: flex; }
    .violation-dialog {
      width: min(480px, 100%);
      max-height: min(90dvh, 720px);
      overflow: auto;
      background: #0f172a;
      border: 1px solid rgba(148, 163, 184, 0.25);
      border-radius: 18px;
      padding: 22px;
      box-shadow: 0 24px 70px rgba(0,0,0,.45);
      color: #fff;
      text-align: left;
    }
    .violation-dialog h2 { margin: 0 0 8px; font-size: 18px; font-weight: 800; }
    .violation-dialog p { color: #cbd5e1; font-size: 13.5px; line-height: 1.55; margin: 0 0 14px; }
    .violation-dialog label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 6px; color: #e2e8f0; }
    .violation-dialog select,
    .violation-dialog textarea {
      width: 100%;
      box-sizing: border-box;
      border-radius: 10px;
      border: 1px solid rgba(148, 163, 184, 0.3);
      background: #1e293b;
      color: #fff;
      padding: 10px 12px;
      font: inherit;
      margin-bottom: 12px;
    }
    .violation-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      justify-content: flex-end;
      margin-top: 8px;
    }
    .violation-actions button {
      height: 40px;
      border-radius: 10px;
      border: 1px solid rgba(148, 163, 184, 0.25);
      padding: 0 14px;
      font-weight: 800;
      cursor: pointer;
      font-size: 13px;
    }
    .violation-actions .ghost { background: #1e293b; color: #fff; }
    .violation-actions .primary { background: #2563eb; color: #fff; border-color: #2563eb; }
    .violation-actions .warn { background: #b45309; color: #fff; border-color: #b45309; }
    .violation-actions .danger { background: #dc2626; color: #fff; border-color: #dc2626; }
    @media (max-width: 720px) {
      .media-permission-gate {
        padding: calc(16px + env(safe-area-inset-top, 0px)) calc(16px + env(safe-area-inset-right, 0px)) calc(16px + env(safe-area-inset-bottom, 0px)) calc(16px + env(safe-area-inset-left, 0px));
        align-items: flex-end;
      }
      .media-permission-dialog {
        width: 100%;
        max-height: 90dvh;
        overflow: auto;
        border-radius: 16px 16px 0 0;
        -webkit-overflow-scrolling: touch;
      }
      .media-permission-actions button {
        min-height: 48px;
        font-size: 16px;
        width: 100%;
      }
      .media-permission-actions .media-permission-leave {
        background: transparent;
        color: #fca5a5;
        border-color: rgba(248, 113, 113, 0.45);
      }
      .media-permission-actions {
        flex-direction: column;
        gap: 10px;
      }
      .end-modal {
        padding: calc(12px + env(safe-area-inset-top, 0px)) calc(12px + env(safe-area-inset-right, 0px)) calc(12px + env(safe-area-inset-bottom, 0px)) calc(12px + env(safe-area-inset-left, 0px));
        align-items: flex-end;
      }
      .end-dialog {
        border-radius: 16px 16px 0 0;
        padding: 20px 16px calc(20px + env(safe-area-inset-bottom, 0px));
      }
      .end-actions {
        flex-direction: column;
        width: 100%;
      }
      .end-actions button {
        width: 100%;
        min-height: 48px;
        font-size: 16px;
      }
      .extend-toast {
        top: calc(64px + env(safe-area-inset-top, 0px));
        left: calc(12px + env(safe-area-inset-left, 0px));
        right: calc(12px + env(safe-area-inset-right, 0px));
        transform: none;
        max-width: none;
      }
    }
    .extend-toast {
      position: fixed;
      top: 80px;
      left: 50%;
      transform: translateX(-50%);
      padding: 10px 18px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 700;
      z-index: 100030;
      display: none;
      max-width: min(520px, calc(100% - 32px));
      text-align: center;
      box-shadow: 0 10px 25px rgba(0,0,0,.25);
    }
    .extend-toast.show { display: block; }
    .extend-toast.success { background: #166534; color: #dcfce7; border: 1px solid #22c55e; }
    .extend-toast.error { background: #7f1d1d; color: #fee2e2; border: 1px solid #ef4444; }
    .demo-connect-tip {
      position: fixed;
      top: 64px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 1200;
      max-width: min(560px, calc(100vw - 24px));
      background: rgba(15,23,42,.95);
      border: 1px solid rgba(148,163,184,.35);
      color: #e2e8f0;
      border-radius: 12px;
      padding: 10px 14px;
      font-size: 12px;
      font-weight: 600;
      line-height: 1.45;
    }
    .compact-hint {
      display: none;
      margin: 0 20px 90px;
      padding: 14px 16px;
      border-radius: 12px;
      background: rgba(30, 41, 59, 0.92);
      border: 1px solid rgba(148, 163, 184, 0.2);
      color: #cbd5e1;
      font-size: 13px;
      line-height: 1.5;
    }
    body.compact-mode .compact-hint { display: block; }
    .media-permission-gate {
      position: fixed;
      inset: 0;
      z-index: 100050;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(2, 6, 23, 0.92);
      backdrop-filter: blur(8px);
    }
    .media-permission-gate.is-hidden { display: none; }
    .media-permission-dialog {
      width: min(440px, 100%);
      background: #0f172a;
      border: 1px solid rgba(148, 163, 184, 0.25);
      border-radius: 18px;
      padding: 26px 24px;
      box-shadow: 0 24px 70px rgba(0,0,0,.45);
      text-align: center;
    }
    .media-permission-icon {
      width: 58px;
      height: 58px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 14px;
      background: rgba(94, 234, 212, 0.12);
      color: #5eead4;
      border: 1px solid rgba(94, 234, 212, 0.25);
    }
    .media-permission-title { font-size: 20px; font-weight: 800; margin: 0 0 8px; }
    .media-permission-copy { color: #cbd5e1; font-size: 13.5px; line-height: 1.55; margin: 0 0 16px; }
    .media-permission-warn {
      background: rgba(251, 191, 36, 0.12);
      border: 1px solid rgba(251, 191, 36, 0.35);
      color: #fde68a;
      border-radius: 10px;
      padding: 12px 14px;
      font-size: 12.5px;
      line-height: 1.5;
      text-align: left;
      margin-bottom: 16px;
    }
    .media-permission-error {
      background: rgba(239, 68, 68, 0.12);
      border: 1px solid rgba(248, 113, 113, 0.35);
      color: #fecaca;
      border-radius: 10px;
      padding: 12px 14px;
      font-size: 12.5px;
      line-height: 1.5;
      text-align: left;
      margin-bottom: 16px;
      display: none;
    }
    .media-permission-error.show { display: block; }
    .media-permission-status {
      font-size: 12px;
      color: #94a3b8;
      margin-bottom: 14px;
      min-height: 18px;
    }
    .media-permission-actions {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .media-permission-actions button {
      height: 44px;
      border-radius: 12px;
      border: none;
      font-weight: 800;
      font-size: 14px;
      cursor: pointer;
    }
    .media-permission-actions .primary {
      background: linear-gradient(135deg, #018a93, #0d9488);
      color: #fff;
    }
    .media-permission-actions .secondary {
      background: #1e293b;
      color: #e2e8f0;
      border: 1px solid rgba(148, 163, 184, 0.25);
    }
    .media-permission-actions .media-permission-leave {
      background: transparent;
      color: #fca5a5;
      border: 1px solid rgba(248, 113, 113, 0.4);
    }
    .media-permission-actions button:disabled { opacity: .65; cursor: not-allowed; }
    #extensionPrompt {
      display: none;
      position: fixed;
      top: 80px;
      left: 50%;
      transform: translateX(-50%);
      background: #fbbf24;
      color: #000;
      padding: 10px 20px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 700;
      box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2);
      z-index: 100025;
      align-items: center;
      gap: 12px;
    }
    .tts-typing-badge {
      font-size: 11px;
      font-weight: 800;
      color: #fde68a;
      background: rgba(180,83,9,.35);
      border: 1px solid rgba(251,191,36,.4);
      padding: 3px 8px;
      border-radius: 999px;
    }
  </style>
</head>
<body
  class="<?= $is_patient ? 'role-patient' : 'role-provider' ?><?= !empty($_GET['embedded']) ? ' embedded-shell' : '' ?>"
  data-csrf="<?= htmlspecialchars($pageCsrfToken, ENT_QUOTES, 'UTF-8') ?>"
  data-asset-base="<?= htmlspecialchars(ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>"
>
<?php /* No boot loader overlay â€” dual Chrome tabs must be interactive immediately. */ ?>

  <div id="mediaPermissionGate" class="media-permission-gate" role="dialog" aria-modal="true" aria-labelledby="mediaPermissionTitle">
    <div class="media-permission-dialog">
      <div class="media-permission-icon" aria-hidden="true">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
      </div>
      <h2 id="mediaPermissionTitle" class="media-permission-title">Allow camera &amp; microphone</h2>
      <p class="media-permission-copy">Tap the button below, then choose <strong>Allow</strong> when your browser asks. This is required to join the video consultation.</p>
      <div id="secureContextWarning" class="media-permission-warn" style="display:none;"></div>
      <div id="mediaPermissionError" class="media-permission-error" role="alert"></div>
      <div id="mediaPermissionStatus" class="media-permission-status">Waiting for you to allow access&hellip;</div>
      <div class="media-permission-actions">
        <button type="button" class="primary" id="btnAllowBoth">Allow camera &amp; microphone</button>
        <button type="button" class="secondary" id="btnAllowAudio">Join with audio only</button>
        <button type="button" class="secondary" id="btnRetryMedia" style="display:none;">Try again</button>
        <button type="button" class="secondary media-permission-leave" id="btnLeaveFromGate">Leave consultation</button>
      </div>
    </div>
  </div>

  <?php if (!$is_patient): ?>
  <div class="mc-vc-top-actions" id="topActions">
    <a href="<?= htmlspecialchars(ASSET_BASE . '/views/provider/consultation_session.php?id=' . $consultation_id) ?>" id="sessionAiLink">Session &amp; AI</a>
    <button type="button" id="minimizeVideoBtn" style="display:none;">Minimize video</button>
    <button type="button" id="compactModeBtn">Compact view</button>
  </div>
  <?php endif; ?>

  <div id="extendToast" class="extend-toast" role="status" aria-live="polite"></div>

  <div id="mcVideoConsultRoot" class="mc-vc-root" aria-label="Video consultation">
    <header class="mc-vc-header" id="mcVcHeader">
      <div class="mc-vc-participant" id="mcVcRemoteParticipant">
        <div class="mc-vc-avatar" aria-hidden="true"><?= $is_patient ? htmlspecialchars($provider_initials) : htmlspecialchars($patient_initials) ?></div>
        <div class="mc-vc-participant-text">
          <div class="mc-vc-participant-name"><?= htmlspecialchars($is_patient ? $provider_name : $patient_name) ?></div>
          <div class="mc-vc-participant-sub"><?= $is_patient ? htmlspecialchars($provider_specialty) : 'Patient' ?></div>
        </div>
      </div>
      <div class="mc-vc-header-meta">
        <span class="mc-vc-pill mc-vc-pill--secure mc-vc-secure-label" title="WebRTC encrypted peer connection">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Secure
        </span>
        <span class="mc-vc-pill mc-vc-pill--duration" id="consultDuration" title="Consultation duration">00:00</span>
        <span class="mc-vc-pill mc-vc-pill--timer mc-vc-slot-timer" id="timerDisplay" title="Time remaining in slot"><?= sprintf('%02d:%02d', (int) floor($seconds_remaining / 60), $seconds_remaining % 60) ?></span>
        <?php if (!$is_patient): ?>
        <button type="button" class="mc-vc-pill extend-btn" id="extendBtn" onclick="requestExtension(15)">+15 min</button>
        <?php endif; ?>
      </div>
    </header>

    <div class="mc-vc-status-bar" aria-live="polite">
      <div class="mc-vc-live-dot live-dot" id="mcVcLiveDot"></div>
      <span class="mc-vc-call-status" id="callStatus">Connecting to secure serverâ€¦</span>
      <div class="mc-vc-media-status media-status">
        <span id="mediaStatusMic">ðŸŽ¤ Microphoneâ€¦</span>
        <span id="mediaStatusCam">ðŸ“· Cameraâ€¦</span>
        <span id="mediaStatusConn" class="mc-vc-pill--network">â—Œ Connectingâ€¦</span>
        <span id="ttsTypingBadge" class="tts-typing-badge" hidden>Typing via Text-to-Speechâ€¦</span>
      </div>
    </div>

    <div class="mc-vc-stage" id="mcVcStage">
      <div class="mc-vc-main" id="mcVcMain">
        <div id="mcVcMainSlot"></div>
        <span class="mc-vc-main-label" id="mcVcMainLabel"></span>
      </div>
      <div class="mc-vc-pip" id="mcVcPip" data-corner="bottom-right">
        <div id="mcVcPipSlot"></div>
        <span class="mc-vc-pip-label" id="mcVcPipLabel"></span>
        <button type="button" class="mc-vc-pip-swap" id="mcVcSwapBtn" title="Switch main view" aria-label="Switch main view">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>
        </button>
      </div>
      <div class="mc-vc-overlay" id="mcVcOverlay" aria-hidden="true">
        <div class="mc-vc-overlay-card">
          <div class="mc-vc-overlay-title" id="mcVcOverlayTitle"></div>
          <div class="mc-vc-overlay-sub" id="mcVcOverlaySub"></div>
          <button type="button" class="mc-vc-overlay-retry" id="retryConnectBtn" hidden>Retry connection</button>
        </div>
      </div>
      <div id="mcVcWaitingCard" class="mc-vc-waiting-card" hidden>
        <div class="mc-vc-waiting-card__inner">
          <p class="mc-vc-waiting-card__eyebrow"><?= $is_patient ? 'Preparing your visit' : 'Preparing session' ?></p>
          <h2 class="mc-vc-waiting-card__title" id="mcVcWaitingTitle"><?= $is_patient ? 'Waiting for your healthcare provider' : 'Waiting for your patient' ?></h2>
          <dl class="mc-vc-waiting-card__meta" id="mcVcWaitingMeta"></dl>
          <p class="mc-vc-waiting-card__status" id="mcVcWaitingStatus"></p>
          <button type="button" class="mc-vc-overlay-retry" id="mcVcWaitingRetry" hidden>Retry connection</button>
        </div>
      </div>
    </div>

    <!-- Hidden video elements (mounted into main/PiP slots by UI module) -->
    <video id="localVideo" autoplay muted playsinline style="display:none"></video>
    <video id="remoteVideo" autoplay playsinline style="display:none"></video>
    <button type="button" id="enableSoundBtn" class="mc-vc-enable-sound enable-sound-btn" hidden>🔊 Enable Audio</button>
    <span id="remoteName" hidden><?= htmlspecialchars($other_name) ?></span>

    <div class="mc-vc-controls video-call-controls" id="mcVcControls">
      <div class="mc-vc-controls-inner">
        <div class="mc-vc-controls-primary" role="group" aria-label="Call controls">
          <button class="mc-vc-btn btn-mute" id="muteAudio" onclick="toggleAudio()" title="Mute microphone" aria-pressed="false" aria-label="Mute microphone">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4M8 22h8"/></svg>
          </button>
          <button class="mc-vc-btn btn-mute" id="toggleVideo" onclick="toggleVideo()" title="Turn camera on or off" aria-label="Toggle camera">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
          </button>
          <button type="button" class="mc-vc-btn mc-vc-btn--mobile-only" id="mcVcFlipBtn" title="Switch camera" aria-label="Switch front or back camera">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
          </button>
          <button type="button" class="mc-vc-btn mc-vc-btn--mobile-only mc-vc-btn--speaker" id="mcVcSpeakerBtn" title="Speaker on or off" aria-label="Toggle speaker">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
          </button>
          <button type="button" class="mc-vc-btn" id="mcVcFullscreenBtn" title="Enter fullscreen" aria-label="Enter fullscreen">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
          </button>
          <button type="button" class="mc-vc-btn mc-vc-btn--desktop-only" id="mcVcMinimizeBtn" title="Minimize call" aria-label="Minimize call">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V5a1 1 0 0 1 1-1h4M18 9V5a1 1 0 0 0-1-1h-4M6 15v4a1 1 0 0 0 1 1h4M18 15v4a1 1 0 0 1-1 1h-4"/></svg>
          </button>
        </div>
        <div class="mc-vc-controls-secondary" role="group" aria-label="Call actions">
          <?php if (!$is_patient): ?>
          <button type="button" class="mc-vc-btn mc-vc-btn--report" id="violationReportBtn" title="Report possible violation" aria-label="Report possible violation">Report</button>
          <?php endif; ?>
          <button type="button" class="mc-vc-btn mc-vc-btn--end btn-end" id="endCallBtn"><?= $is_patient ? 'Leave' : 'End Call' ?></button>
        </div>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/partials/video_room_panels.php'; ?>

  <?php if (!$is_patient): ?>
  <div class="compact-hint" id="compactHint">
    Use <strong>Session &amp; AI</strong> for the Clinical Support Panel (finalize chief complaint, re-assess risk/conditions/questions/actions) and SOAP notes.
    If the call is embedded above the panel, tap <strong>Minimize video</strong> or <strong>Compact view</strong>.
  </div>
  <?php endif; ?>

  <div id="extensionPrompt">
    <span>5 minutes remaining. Would you like to extend?</span>
    <?php if($role === 'provider'): ?>
    <button onclick="requestExtension(15)" style="background:#000; color:#fff; border:none; padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer">Extend 15m</button>
    <?php endif; ?>
  </div>

  <div id="muteTtsBanner" class="mute-tts-banner" aria-hidden="true" role="status">
    <?php if ($is_patient): ?>
      Your microphone is muted. Type below â€” the provider will hear it as speech and see the text.
    <?php else: ?>
      Your microphone is muted. Type below â€” the patient will hear it as speech and see the text.
    <?php endif; ?>
  </div>
  <div id="remoteMuteBanner" class="remote-mute-banner" aria-hidden="true" role="status">
    <?php if ($is_patient): ?>
      Provider microphone is muted. Wait for typed voice messages â€” they will play as speech here.
    <?php else: ?>
      Patient microphone is muted. Their typed messages will appear here and play as speech.
    <?php endif; ?>
  </div>
  <div id="muteTtsPanel" class="mute-tts-panel" aria-hidden="true" role="region" aria-label="Text communication while muted">
    <p class="mute-tts-panel__title">Text message while muted</p>
    <p class="mute-tts-panel__sub"><?= $is_patient
      ? 'Type your message and press Send. Your provider will hear it spoken aloud and see the text.'
      : 'Type your message and press Send. The patient will hear it spoken aloud and see the text.' ?></p>
    <label for="muteTtsInput" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Type your message</label>
    <textarea id="muteTtsInput" maxlength="500" placeholder="<?= $is_patient
      ? 'Example: I have a headache for three days.'
      : 'Example: Please describe your pain on a scale of 1 to 10.' ?>"></textarea>
    <div class="mute-tts-panel__meta">
      <span id="muteTtsCharCount" class="mute-tts-char">0 / 500</span>
      <div class="mute-tts-actions">
        <button type="button" class="clear" id="muteTtsClearBtn">Clear</button>
        <button type="button" class="speak" id="muteTtsSpeakBtn">Send</button>
      </div>
    </div>
    <div id="muteTtsStatus" class="mute-tts-status" hidden></div>
    <div id="muteTtsLog" class="mute-tts-log" aria-live="polite"></div>
  </div>
  <div id="muteTtsReceivePanel" class="mute-tts-receive-panel" aria-label="<?= $is_patient ? 'Messages from provider' : 'Messages from patient' ?>">
    <div class="mute-tts-receive-panel__title"><?= $is_patient ? 'Messages from provider' : 'Messages from patient' ?></div>
    <div id="muteTtsReceiveLog" class="mute-tts-receive-log mute-tts-log" aria-live="polite"></div>
  </div>
  <div id="muteTtsRestoreToast" class="mute-tts-restore" role="status">Voice communication restored.</div>
  <div id="muteTtsToast" class="mute-tts-toast" role="status"></div>

  <div class="end-modal" id="endCallModal" role="dialog" aria-modal="true" aria-labelledby="endModalTitle">
    <div class="end-dialog">
      <div id="endModalIcon" class="end-icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.79 19.79 0 0 1 11.19 19a19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.08 4.18 2 2 0 0 1 4.06 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.77.66 2.6a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.48-1.18a2 2 0 0 1 2.11-.45c.83.32 1.7.54 2.6.66A2 2 0 0 1 22 16.92z"/></svg>
      </div>
      <div id="endModalTitle" class="end-title"><?= $is_patient ? 'Leave the video call?' : 'End consultation?' ?></div>
      <div id="endModalCopy" class="end-copy"><?= $is_patient
        ? 'You will disconnect from the video call, but the session stays open. You can rejoin anytime today from your patient dashboard while your doctor is still in session.'
        : 'The consultation room will close for both sides. If recording is active, medConnect will save it before leaving this page.' ?></div>
      <div class="end-actions" id="endModalActions">
        <button type="button" class="keep" onclick="closeEndModal()"><?= $is_patient ? 'Stay on Call' : 'Keep Call' ?></button>
        <button type="button" class="confirm" id="confirmEndBtn" onclick="confirmEndCall()"><?= $is_patient ? 'Leave Call' : 'End Consultation' ?></button>
      </div>
    </div>
  </div>

  <?php if (!$is_patient): ?>
  <div class="violation-modal" id="violationReportModal" role="dialog" aria-modal="true" aria-labelledby="violationModalTitle">
    <div class="violation-dialog">
      <h2 id="violationModalTitle">Report Possible Violation</h2>
      <p>Report a possible violation during this consultation. The report will be reviewed by an authorized administrator. This does not automatically suspend the patient's account.</p>
      <label for="violationReason">Possible violation reason</label>
      <select id="violationReason" required>
        <option value="">Select a reason…</option>
        <?php
        require_once BASE_PATH . '/app/includes/case_reports_schema.php';
        foreach (case_report_valid_video_reasons() as $vr): ?>
        <option value="<?= htmlspecialchars($vr) ?>"><?= htmlspecialchars(case_report_reason_label($vr)) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="violationNotes">Describe what happened (optional)</label>
      <textarea id="violationNotes" rows="3" placeholder="Optional notes for administrators"></textarea>
      <div class="violation-actions">
        <button type="button" class="ghost" id="violationCancelBtn">Cancel</button>
        <button type="button" class="primary" id="violationSubmitBtn">Submit Report</button>
        <button type="button" class="warn" id="violationEndOnlyBtn">End Consultation</button>
        <button type="button" class="danger" id="violationReportEndBtn">Report &amp; End</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script>
    const roomToken = '<?= $token ?>';
    const userRole  = '<?= $role ?>';
    const isPatient = <?= $is_patient ? 'true' : 'false' ?>;
    const consultationId = <?= $consultation_id ?>;
    window.__mcCallEnded = false;
    const apiBase = (function () {
      const fromPhp = String(<?= json_encode((string) ASSET_BASE) ?> || '').replace(/\/$/, '');
      if (fromPhp) return fromPhp;
      // Fallback if bootstrap ASSET_BASE is empty (breaks dual-tab signaling with 404).
      const path = String(window.location.pathname || '');
      if (path.indexOf('/medconnect/') === 0 || path === '/medconnect') return '/medconnect';
      return '';
    })();
    const demoMode = false;
    const demoKey = '';
    const demoExp = 0;
    const demoAs = '';
    console.log('[medConnect] apiBase=', apiBase, 'role=', '<?= $role ?>');
    const peerId = userRole + '-' + roomToken;
    const peerOptions = {
      host: '0.peerjs.com',
      port: 443,
      path: '/',
      secure: true,
      debug: demoMode ? 1 : 0,
      config: {
        iceServers: [
          { urls: 'stun:stun.l.google.com:19302' },
          { urls: 'stun:stun1.l.google.com:19302' }
        ]
      }
    };
    let myPeerJsId = null;
    let remoteDiscoveredId = null;
    let demoBus = null;
    let demoHelloTimer = null;
    let localStream;
    let timeLeft = <?= (int) $seconds_remaining ?>;
    let extendingSession = false;
    let timerInterval;
    let mediaRecorder;
    let recordedChunks = [];
    let canvasStream;
    let canvasContext;
    let drawInterval;
    let uploadPromise; // To wait for upload before redirecting
    let endingCall = false;
    let recordingAudioContext;
    let recordingAudioDestination;
    let remoteAudioConnected = false;
    let callInterval = null;
    let muteTts = null;
    let remoteMediaUnlocked = false;
    let silentAudioFallback = null;
    let patientMayDial = false;
    let mediaJoinAt = 0;
    let callHasRemoteStream = false;
    let localDemoCall = null;
    let syncTimerInterval = null;
    let keepAliveInterval = null;
    let remotePeerLeft = false;

    function dismissBootLoader() {
      try {
        if (window.MedConnectLoader && typeof window.MedConnectLoader.forceHide === 'function') {
          window.MedConnectLoader.forceHide();
        }
        if (window.MedConnectGlobalLoader && typeof window.MedConnectGlobalLoader.forceHide === 'function') {
          window.MedConnectGlobalLoader.forceHide();
        }
      } catch (e) {}
      const boot = document.getElementById('mc-loader-boot');
      if (boot) {
        boot.classList.remove('mc-global-loader--visible', 'mc-loader--visible');
        boot.setAttribute('hidden', '');
        boot.setAttribute('aria-hidden', 'true');
        boot.setAttribute('aria-busy', 'false');
      }
      document.body.classList.remove(
        'mc-global-loader-active',
        'mc-loader-active',
        'mc-login-loading-active',
        'mc-global-loader--boot-active',
        'mc-global-loader--modal-active'
      );
    }

    function remotePeerId() {
      // Dual-tab Chrome demo: PeerJS cloud custom IDs often never resolve.
      // Tabs discover each other via BroadcastChannel and dial the live PeerJS id.
      if (demoMode && remoteDiscoveredId) {
        return remoteDiscoveredId;
      }
      return (userRole === 'provider' ? 'patient-' : 'provider-') + roomToken;
    }

    function announceDemoPeer() {
      if (!demoMode || !demoBus || !myPeerJsId) return;
      demoBus.postMessage({
        type: 'peer-hello',
        token: roomToken,
        role: userRole,
        peerId: myPeerJsId,
        hasMedia: !!localStream,
        at: Date.now(),
      });
    }

    function setupDemoBus() {
      if (!demoMode || typeof BroadcastChannel === 'undefined') return;
      try {
        demoBus = new BroadcastChannel('medconnect-demo-' + roomToken);
      } catch (e) {
        console.warn('BroadcastChannel unavailable for dual-tab demo:', e);
        return;
      }
      demoBus.onmessage = (ev) => {
        const msg = ev.data || {};
        if (msg.token && msg.token !== roomToken) return;
        if (msg.type === 'peer-hello' && msg.role && msg.role !== userRole && msg.peerId) {
          const changed = remoteDiscoveredId !== msg.peerId;
          remoteDiscoveredId = msg.peerId;
          if (changed) {
            console.log('Demo discovered remote peer:', remoteDiscoveredId, 'as', msg.role);
            document.getElementById('callStatus').textContent = 'Found other tab â€” connectingâ€¦';
          }
          // Answer their hello so both sides know each other even if one started later.
          announceDemoPeer();
          if (localStream && window.McWebrtcPeerCall && McWebrtcPeerCall.isReady() && !callHasRemoteStream) {
            beginConnectionRetries();
          }
          return;
        }
        if (msg.type === 'peer-bye' && msg.role !== userRole) {
          remoteDiscoveredId = null;
          return;
        }
        // Same-browser mute TTS / mute state backup (does not need PeerJS data channel).
        if ((msg.type === 'mute_tts' || msg.type === 'mute_state') && muteTts) {
          muteTts.handleIncomingData(msg);
        }
        if (msg.type === 'peer_left') {
          handlePeerLeftMessage(msg);
        }
      };
      if (demoHelloTimer) clearInterval(demoHelloTimer);
      demoHelloTimer = setInterval(announceDemoPeer, 2000);
    }

    function onDemoRemoteStream(remoteStream) {
      console.log('Demo local WebRTC remote stream received');
      callHasRemoteStream = true;
      if (callInterval) {
        clearInterval(callInterval);
        callInterval = null;
      }
      const audioTracks = remoteStream.getAudioTracks ? remoteStream.getAudioTracks() : [];
      audioTracks.forEach((track) => { track.enabled = true; });

      attachRemoteCallStream(remoteStream).then((ok) => {
        if (!ok) {
          showEnableSoundButton(true);
          document.getElementById('callStatus').textContent = 'Connected â€” tap to enable sound';
        }
      });
      if (consultUi && typeof consultUi.mountVideos === 'function') consultUi.mountVideos();
      document.getElementById('remoteName').textContent = '<?= htmlspecialchars($other_name) ?>';
      setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.CONNECTED : 'connected', {
        callStatusText: 'Connected',
      });
      syncMediaStatus({ connectionLabel: 'â— Good Connection', connectionState: 'connected' });
      const tip = document.getElementById('demoConnectTip');
      if (tip) tip.style.display = 'none';
      connectRemoteAudioToRecording();
      setTimeout(() => unlockRemoteAudio(), 200);
      setTimeout(() => unlockRemoteAudio(), 800);
      if (userRole === 'provider' && (!mediaRecorder || mediaRecorder.state === 'inactive')) {
        startRecording();
      }
      // Re-announce mute so the other side sees mute banner if we muted before connect.
      if (muteTts && typeof muteTts.syncMuteStateToPeer === 'function') {
        muteTts.syncMuteStateToPeer();
      }
    }

    function ensureLocalDemoCall() {
      if (!demoMode || !window.McDemoLocalWebrtc) return localDemoCall;
      if (localDemoCall) return localDemoCall;
      localDemoCall = window.McDemoLocalWebrtc.createController({
        roomToken: roomToken,
        role: userRole,
        apiBase: apiBase,
        demoKey: demoKey,
        demoExp: demoExp,
        getLocalStream: () => localStream,
        onRemoteStream: onDemoRemoteStream,
        onStatus: (text) => {
          if (callHasRemoteStream && text === 'Connected') return;
          document.getElementById('callStatus').textContent = text || '';
        },
        onData: (data) => {
          if (muteTts) muteTts.handleIncomingData(data);
        },
      });
      return localDemoCall;
    }

    function sendMuteData(payload) {
      let sent = false;
      if (window.McWebrtcPeerCall && McWebrtcPeerCall.sendData(payload)) {
        sent = true;
      }
      // Same-browser demo: BroadcastChannel paths (local WebRTC + legacy demo bus).
      if (demoMode) {
        try {
          if (localDemoCall) {
            localDemoCall.send(payload);
            sent = true;
          }
          if (demoBus) {
            demoBus.postMessage(Object.assign({}, payload, {
              token: roomToken,
              role: userRole,
              at: Date.now(),
            }));
            sent = true;
          }
        } catch (e) {}
      }
      return sent;
    }

    function openDataChannel() {
      if (!window.McWebrtcPeerCall || endingCall) return;
      if (!McWebrtcPeerCall.isReady()) return;
      const target = remotePeerId();
      if (!target) return;
      if (demoMode && !remoteDiscoveredId) return;
      McWebrtcPeerCall.openDataChannel(target);
    }

    function onPeerOpen(id) {
      console.log('Peer open with ID:', id);
      myPeerJsId = id;
      announceDemoPeer();
      openDataChannel();
      if (!localStream) {
        setPermissionStatus('Connected - tap below to allow camera and microphone.');
        setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.PERMISSION : 'permission', {
          callStatusText: 'Allow camera & microphone to join',
        });
      } else {
        beginConnectionRetries();
      }
    }

    let peerInitialized = false;

    function createPeer() {
      if (!window.McWebrtcPeerCall) return;
      if (peerInitialized && McWebrtcPeerCall.isReady()) return;
      peerInitialized = true;
      McWebrtcPeerCall.init(demoMode ? undefined : peerId, {
        peerOptions: peerOptions,
        useAutoPeerId: demoMode,
        onRecreate: function () { createPeer(); },
        onNeedsRedial: function () {
          if (endingCall || !localStream) return;
          patientMayDial = true;
          flushPendingCall();
          openDataChannel();
          startCall();
        },
      });
    }

    function handleConnectionFailed(reason) {
      if (endingCall) return;
      setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.RECONNECTING : 'reconnecting', {
        callStatusText: 'Connection interrupted — tap Retry if this persists',
      });
      if (consultUi && typeof consultUi.setConnectionFailed === 'function') {
        consultUi.setConnectionFailed(true);
      }
      if (window.McVideoRoomEnhancements && typeof McVideoRoomEnhancements.setWaitingRetryVisible === 'function') {
        McVideoRoomEnhancements.setWaitingRetryVisible(true);
      }
      console.warn('[medConnect] WebRTC connection failed:', reason);
    }

    function handleConnectionRecovered() {
      if (consultUi && typeof consultUi.setConnectionFailed === 'function') {
        consultUi.setConnectionFailed(false);
      }
      if (window.McVideoRoomEnhancements && typeof McVideoRoomEnhancements.setWaitingRetryVisible === 'function') {
        McVideoRoomEnhancements.setWaitingRetryVisible(false);
      }
      if (callHasRemoteStream) {
        setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.CONNECTED : 'connected', {
          callStatusText: 'Connected',
        });
        if (consultUi && typeof consultUi.setOverlay === 'function') {
          consultUi.setOverlay('', '', false, { showRetry: false });
        }
        setTimeout(() => unlockRemoteAudio(), 200);
      }
    }

    function setupWebrtcEvents() {
      if (!window.McWebrtcPeerCall) return;
      const rtc = McWebrtcPeerCall;

      rtc.on('open', function (ev) { onPeerOpen(ev.id); });

      rtc.on('data-open', function () {
        console.log('Peer data channel open');
        if (userRole === 'patient' && !callHasRemoteStream && !endingCall) {
          patientMayDial = true;
          startCall();
        }
        if (muteTts && typeof muteTts.syncMuteStateToPeer === 'function') {
          muteTts.syncMuteStateToPeer();
        }
      });

      rtc.on('data', function (ev) {
        if (handlePeerLeftMessage(ev.data)) return;
        if (muteTts) muteTts.handleIncomingData(ev.data);
      });

      rtc.on('incoming-call', function (ev) {
        console.log('Incoming call from:', ev.peer);
        if (demoMode && ev.peer) remoteDiscoveredId = ev.peer;
      });

      rtc.on('incoming-call-waiting-media', function () {
        setPermissionStatus('Other participant is waiting — allow camera/microphone to connect.');
        document.getElementById('callStatus').textContent = 'Participant ready — allow access to join';
      });

      rtc.on('remote-stream', function (ev) {
        handleConnectionRecovered();
        onRemoteStreamReceived(ev.stream, ev.peer);
      });

      rtc.on('call-close', function (ev) {
        console.log('Call closed');
        callHasRemoteStream = false;
        remoteMediaUnlocked = false;
        showEnableSoundButton(false);
        if (endingCall || (window.McWebrtcPeerCall && McWebrtcPeerCall.isIntentionalLeave && McWebrtcPeerCall.isIntentionalLeave())) {
          return;
        }
        if (!endingCall && localStream && !callInterval) {
          setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.RECONNECTING : 'reconnecting', {
            callStatusText: 'Reconnecting…',
          });
          beginConnectionRetries();
        }
      });

      rtc.on('recovering', function () {
        if (endingCall) return;
        setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.RECONNECTING : 'reconnecting', {
          callStatusText: 'Reconnecting…',
        });
        if (consultUi && typeof consultUi.setOverlay === 'function') {
          consultUi.setOverlay('Reconnecting…', 'Restoring your secure video connection automatically.', true, { showRetry: false });
        }
      });

      rtc.on('recovered', function () {
        handleConnectionRecovered();
      });

      rtc.on('connection-failed', function (ev) {
        handleConnectionFailed(ev && ev.reason ? ev.reason : 'unknown');
      });

      rtc.on('needs-redial', function () {
        if (endingCall || !localStream) return;
        if (window.McWebrtcPeerCall && McWebrtcPeerCall.isIntentionalLeave && McWebrtcPeerCall.isIntentionalLeave()) return;
        patientMayDial = true;
        flushPendingCall();
        openDataChannel();
        startCall();
      });

      rtc.on('call-error', function (ev) {
        console.error('Call error:', ev.error);
      });

      rtc.on('disconnected', function () {
        console.warn('Peer disconnected — reconnecting signaling…');
        setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.RECONNECTING : 'reconnecting');
      });

      rtc.on('error', function (ev) {
        const err = ev.error || {};
        console.error('Peer error:', err);
        const type = err.type ? err.type : '';
        if (type === 'unavailable-id') {
          document.getElementById('callStatus').textContent = 'Signaling ID busy — retrying…';
          McWebrtcPeerCall.recreatePeer('unavailable-id');
          return;
        }
        if (type === 'network' || type === 'server-error' || type === 'socket-error' || type === 'socket-closed') {
          document.getElementById('callStatus').textContent = 'Signaling reconnecting…';
          McWebrtcPeerCall.recreatePeer(type);
          return;
        }
        if (type === 'peer-unavailable') {
          if (demoMode) {
            announceDemoPeer();
            remoteDiscoveredId = null;
            document.getElementById('callStatus').textContent = 'Looking for other tab…';
            return;
          }
          setCallPhase(
            userRole === 'provider'
              ? (window.McVideoCallCore ? window.McVideoCallCore.STATUS.WAITING_PATIENT : 'waiting_patient')
              : (window.McVideoCallCore ? window.McVideoCallCore.STATUS.WAITING_PROVIDER : 'waiting_provider')
          );
        }
      });
    }

    function onRemoteStreamReceived(remoteStream, peerLabel) {
      console.log('Remote stream received from:', peerLabel);
      callHasRemoteStream = true;
      if (callInterval) {
        clearInterval(callInterval);
        callInterval = null;
      }

      const audioTracks = remoteStream.getAudioTracks ? remoteStream.getAudioTracks() : [];
      audioTracks.forEach((track) => { track.enabled = true; });

      if (!audioTracks.length) {
        document.getElementById('callStatus').textContent = 'Connected — remote mic missing. Ask other tab to rejoin with audio.';
      }

      attachRemoteCallStream(remoteStream).then((ok) => {
        if (!ok) {
          showEnableSoundButton(true);
          document.getElementById('callStatus').textContent = 'Connected — tap to enable sound';
        }
      });
      if (consultUi && typeof consultUi.mountVideos === 'function') consultUi.mountVideos();
      document.getElementById('remoteName').textContent = '<?= htmlspecialchars($other_name) ?>';
      setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.CONNECTED : 'connected', {
        callStatusText: 'Connected',
      });
      if (consultUi && typeof consultUi.setOverlay === 'function') {
        consultUi.setOverlay('', '', false, { showRetry: false });
      }
      if (consultUi && typeof consultUi.startDurationTimer === 'function') {
        consultUi.startDurationTimer();
      }
      syncMediaStatus({ connectionLabel: '● Good Connection', connectionState: 'connected' });
      const tip = document.getElementById('demoConnectTip');
      if (tip) tip.style.display = 'none';
      connectRemoteAudioToRecording();
      setTimeout(() => unlockRemoteAudio(), 200);
      setTimeout(() => unlockRemoteAudio(), 1000);
      if (userRole === 'provider' && (!mediaRecorder || mediaRecorder.state === 'inactive')) {
        startRecording();
      }
      openDataChannel();
      if (muteTts && typeof muteTts.syncMuteStateToPeer === 'function') {
        muteTts.syncMuteStateToPeer();
      }
    }

    async function createSilentMediaStream() {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) {
        throw new Error('Silent media fallback unavailable');
      }
      const ctx = new AudioCtx();
      const oscillator = ctx.createOscillator();
      const gain = ctx.createGain();
      const dest = ctx.createMediaStreamDestination();
      gain.gain.value = 0.0001; // effectively silent, still a live track for WebRTC
      oscillator.connect(gain);
      gain.connect(dest);
      oscillator.start();
      silentAudioFallback = { ctx, oscillator };
      return dest.stream;
    }

    function showEnableSoundButton(show) {
      const btn = document.getElementById('enableSoundBtn');
      if (!btn) return;
      btn.hidden = !show;
    }

    function syncMediaStatus(extras = {}) {
      if (window.McVideoCallCore) {
        window.McVideoCallCore.updateMediaStatusUI(localStream, extras);
      }
    }

    function setCallPhase(statusKey, overrides = {}) {
      if (window.McVideoCallCore) {
        window.McVideoCallCore.setCallPhase(userRole, statusKey, Object.assign({ stream: localStream }, overrides));
      } else if (overrides.callStatusText) {
        document.getElementById('callStatus').textContent = overrides.callStatusText;
      }
      notifyParentCallState(statusKey, overrides);
    }

    function unlockRemoteAudio() {
      if (window.McVideoCallCore) {
        return window.McVideoCallCore.unlockRemoteAudio().then((ok) => {
          if (ok) remoteMediaUnlocked = true;
          return ok;
        });
      }
      const videoEl = document.getElementById('remoteVideo');
      if (!videoEl || !videoEl.srcObject) return Promise.resolve(false);
      videoEl.muted = false;
      videoEl.volume = 1;
      remoteMediaUnlocked = true;
      showEnableSoundButton(false);
      const playPromise = videoEl.play();
      if (playPromise && typeof playPromise.catch === 'function') {
        return playPromise.then(() => true).catch(() => {
          showEnableSoundButton(true);
          return false;
        });
      }
      return Promise.resolve(true);
    }

    function attachStreamToVideo(videoEl, stream, options = {}) {
      if (!videoEl || !stream) return;
      videoEl.srcObject = stream;
      videoEl.muted = true; // local always muted; remote sound via #remoteAudio
      if (options.muted === false && window.McVideoCallCore) {
        // Remote streams use attachRemoteMedia instead.
        return;
      }
      const playPromise = videoEl.play();
      if (playPromise && typeof playPromise.catch === 'function') {
        playPromise.catch((err) => console.warn('Video play blocked:', err));
      }
    }

    function attachRemoteCallStream(remoteStream) {
      if (window.McVideoCallCore) {
        return window.McVideoCallCore.attachRemoteMedia(remoteStream).then((ok) => {
          if (ok) remoteMediaUnlocked = true;
          return ok;
        });
      }
      attachStreamToVideo(document.getElementById('remoteVideo'), remoteStream, { muted: false });
      return unlockRemoteAudio();
    }

    function isCallConnected() {
      if (demoMode && localDemoCall && localDemoCall.isConnected()) return true;
      return window.McWebrtcPeerCall ? McWebrtcPeerCall.isCallConnected() : false;
    }

    function hasActiveOrPendingCall() {
      if (!window.McWebrtcPeerCall) return false;
      return !!(McWebrtcPeerCall.getCurrentCall() || McWebrtcPeerCall.isOutboundInFlight());
    }

    function flushPendingCall() {
      if (window.McWebrtcPeerCall) McWebrtcPeerCall.flushPendingCall();
    }

    function startCall() {
      if (demoMode) return;
      if (!window.McWebrtcPeerCall || !McWebrtcPeerCall.isReady() || !localStream || endingCall) return;
      if (isCallConnected() && callHasRemoteStream) return;

      if (userRole === 'patient' && !patientMayDial) {
        setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.WAITING_PROVIDER : 'waiting_provider', {
          callStatusText: 'Online — waiting for doctor to connect...',
        });
        return;
      }

      if (!isCallConnected() || !callHasRemoteStream) {
        setCallPhase(
          userRole === 'provider'
            ? (window.McVideoCallCore ? window.McVideoCallCore.STATUS.WAITING_PATIENT : 'waiting_patient')
            : (window.McVideoCallCore ? window.McVideoCallCore.STATUS.WAITING_PROVIDER : 'waiting_provider'),
          {
            callStatusText: userRole === 'provider'
              ? 'Waiting for Patient…'
              : 'Waiting for Healthcare Provider…',
          }
        );
      }

      const rtc = McWebrtcPeerCall;
      if (rtc.getCurrentCall() && (!rtc.isCallConnected() || !callHasRemoteStream)) {
        rtc.closeCurrentCall();
      }
      if (rtc.isOutboundInFlight()) return;
      McWebrtcPeerCall.flushPendingCall();
      if (rtc.getCurrentCall() && callHasRemoteStream) return;

      const targetId = remotePeerId();
      console.log('Attempting to call:', targetId);
      McWebrtcPeerCall.makeCall(targetId);
    }

    function beginConnectionRetries() {
      if (endingCall) return;
      mediaJoinAt = Date.now();

      // Chrome dual-tab demo: local WebRTC over HTTP signaling relay.
      if (demoMode) {
        if (!window.McDemoLocalWebrtc) {
          document.getElementById('callStatus').textContent = 'Demo script missing â€” hard refresh (Ctrl+F5)';
          return;
        }
        if (!demoKey) {
          document.getElementById('callStatus').textContent = 'Missing demo key â€” reopen from demo launcher';
          return;
        }
        const demo = ensureLocalDemoCall();
        if (demo) {
          dismissBootLoader();
          console.log('[medConnect demo] starting local WebRTC as', userRole, 'token', roomToken.slice(0, 8), 'apiBase', apiBase);
          demo.start();
          document.getElementById('callStatus').textContent = userRole === 'provider'
            ? 'Waiting for Patient tabâ€¦'
            : 'Waiting for Provider tabâ€¦';
          if (callInterval) clearInterval(callInterval);
          callInterval = setInterval(() => {
            if (callHasRemoteStream) {
              clearInterval(callInterval);
              callInterval = null;
              return;
            }
            if (localDemoCall) localDemoCall.start();
          }, 3000);
          return;
        }
      }

      if (userRole === 'provider') {
        patientMayDial = true;
      } else if (userRole === 'patient') {
        // If the doctor is slow to dial, let the patient initiate after a short grace period.
        setTimeout(() => {
          if (!callHasRemoteStream && !endingCall) {
            patientMayDial = true;
            startCall();
          }
        }, 6000);
      }
      flushPendingCall();
      openDataChannel();
      startCall();
      if (callInterval) clearInterval(callInterval);
      callInterval = setInterval(() => {
        openDataChannel();
        startCall();
      }, 2500);
    }

    function isLocalDevHost() {
      const host = window.location.hostname;
      return host === 'localhost' || host === '127.0.0.1' || host === '[::1]';
    }

    function isPrivateLanHost() {
      const host = window.location.hostname;
      return /^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/.test(host);
    }

    function mediaSecureContextReady() {
      return window.isSecureContext || isLocalDevHost();
    }

    function canUseMediaDevices() {
      return mediaSecureContextReady()
        && !!(navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function');
    }

    function setPermissionStatus(message) {
      const el = document.getElementById('mediaPermissionStatus');
      if (el) el.textContent = message || '';
    }

    function showPermissionError(message) {
      const el = document.getElementById('mediaPermissionError');
      if (!el) return;
      el.innerHTML = message;
      el.classList.add('show');
      document.getElementById('btnRetryMedia').style.display = 'block';
    }

    function clearPermissionError() {
      const el = document.getElementById('mediaPermissionError');
      if (!el) return;
      el.textContent = '';
      el.classList.remove('show');
      document.getElementById('btnRetryMedia').style.display = 'none';
    }

    function showSecureContextWarningIfNeeded() {
      const warn = document.getElementById('secureContextWarning');
      if (!warn) return;

      if (mediaSecureContextReady()) {
        warn.style.display = 'none';
        return;
      }

      warn.style.display = 'block';
      const origin = window.location.protocol + '//' + window.location.host;
      if (isPrivateLanHost()) {
        warn.innerHTML =
          '<strong>HTTPS required for phone camera/mic.</strong> You are on <code>' + origin + '</code> over plain HTTP. ' +
          'Mobile browsers block camera and microphone on LAN IP addresses. When you deploy online with <strong>HTTPS</strong>, video will work for patients. ' +
          'For local phone testing now, use an <strong>https://</strong> tunnel (ngrok) or HTTPS on your PC (mkcert).';
      } else {
        warn.innerHTML =
          '<strong>Secure connection required.</strong> Open this site with <strong>https://</strong> so the browser can use camera and microphone.';
      }
    }

    async function refreshPermissionHints() {
      if (!navigator.permissions || !navigator.permissions.query) return;
      try {
        const names = ['camera', 'microphone'];
        const states = await Promise.all(
          names.map((name) => navigator.permissions.query({ name }).then((r) => name + ': ' + r.state).catch(() => name + ': unknown'))
        );
        setPermissionStatus('Browser permission state â€” ' + states.join(' Â· '));
      } catch (e) {
        setPermissionStatus('Tap a button below, then allow access in the browser prompt.');
      }
    }

    function hideMediaPermissionGate() {
      const gate = document.getElementById('mediaPermissionGate');
      if (gate) gate.classList.add('is-hidden');
      document.body.classList.add('media-ready');
      startBackgroundSync();
    }

    let backgroundSyncStarted = false;
    function startBackgroundSync() {
      if (backgroundSyncStarted) return;
      backgroundSyncStarted = true;
      syncTimerFromServer();
      pingSessionKeepAlive();
    }

    function showMediaPermissionGate() {
      const gate = document.getElementById('mediaPermissionGate');
      if (gate) gate.classList.remove('is-hidden');
      document.body.classList.remove('media-ready');
      showSecureContextWarningIfNeeded();
      clearPermissionError();
      setPermissionStatus('Tap a button below to request access.');
      refreshPermissionHints();

      if (!canUseMediaDevices()) {
        const insecure = !mediaSecureContextReady();
        showPermissionError(
          insecure
            ? '<strong>Camera and microphone need HTTPS.</strong> This page is not in a secure context (<code>' +
              window.location.protocol + '//' + window.location.host + '</code>). Deploy with SSL or use an HTTPS URL â€” then tap Allow again.'
            : '<strong>Media devices are not available.</strong> Your browser blocked access. Check site permissions and try another browser.'
        );
        document.getElementById('btnAllowBoth').disabled = true;
        document.getElementById('btnAllowAudio').disabled = true;
      }
    }

    function mediaErrorMessage(err) {
      const name = err && err.name ? err.name : 'Error';
      let tips = '<ul style="margin:8px 0 0 18px;padding:0;">';
      if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
        tips += '<li>Tap <strong>Allow</strong> in the browser prompt.</li>';
        tips += '<li>Brave: Shields off â†’ Site settings â†’ Camera &amp; Microphone â†’ Allow.</li>';
        tips += '<li>Phone settings â†’ Apps â†’ Browser â†’ Permissions.</li>';
      } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        tips += '<li>No camera/mic found on this device.</li>';
        tips += '<li>Try <strong>Audio only</strong> or another device.</li>';
      } else if (name === 'NotReadableError' || name === 'TrackStartError') {
        tips += '<li>Another app may be using the camera (Zoom, Messenger, etc.). Close it and retry.</li>';
      } else if (name === 'SecurityError' || name === 'NotSupportedError') {
        tips += '<li>Use <strong>HTTPS</strong> or <code>localhost</code> â€” HTTP on a phone IP often cannot use camera.</li>';
      } else {
        tips += '<li>Check browser permissions and close other camera apps.</li>';
      }
      tips += '</ul>';
      return '<strong>Could not access microphone/camera</strong> (' + name + ').' + tips;
    }

    async function requestMediaAccess(videoEnabled) {
      if (!canUseMediaDevices()) {
        showPermissionError(mediaErrorMessage({ name: 'NotSupportedError' }));
        return;
      }

      clearPermissionError();
      setPermissionStatus(videoEnabled ? 'Requesting camera and microphoneâ€¦' : 'Requesting microphoneâ€¦');
      document.getElementById('btnAllowBoth').disabled = true;
      document.getElementById('btnAllowAudio').disabled = true;

      const audioConstraints = (window.McVideoCallCore && window.McVideoCallCore.getAudioConstraints)
        ? window.McVideoCallCore.getAudioConstraints()
        : { echoCancellation: true, noiseSuppression: true, autoGainControl: true };

      try {
        localStream = await navigator.mediaDevices.getUserMedia({
          video: videoEnabled ? { facingMode: 'user' } : false,
          audio: audioConstraints
        });
      } catch (err) {
        console.warn('Media request failed:', err);
        if (videoEnabled) {
          try {
            localStream = await navigator.mediaDevices.getUserMedia({ video: false, audio: audioConstraints });
            setPermissionStatus('Camera blocked â€” joined with audio only.');
          } catch (audioErr) {
            // Chrome dual-tab: camera/mic may be locked by the other tab. Join with silent track so PeerJS can still connect.
            try {
              localStream = await createSilentMediaStream();
              setPermissionStatus('Mic busy in the other tab â€” joined with silent audio so the call can connect. Use mute TTS to type.');
              document.getElementById('muteAudio').classList.add('off');
              document.getElementById('toggleVideo').classList.add('off');
            } catch (silentErr) {
              document.getElementById('btnAllowBoth').disabled = false;
              document.getElementById('btnAllowAudio').disabled = false;
              showPermissionError(mediaErrorMessage(audioErr));
              setPermissionStatus('Permission denied or unavailable.');
              setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.PERMISSION : 'permission', {
                callStatusText: 'Waiting for camera/mic permission',
                micPermissionDenied: (audioErr && (audioErr.name === 'NotAllowedError' || audioErr.name === 'PermissionDeniedError')),
              });
              syncMediaStatus({ micPermissionDenied: true });
              return;
            }
          }
        } else {
          try {
            localStream = await createSilentMediaStream();
            setPermissionStatus('Microphone unavailable â€” joined with silent audio. Mute TTS still works for typed voice.');
            document.getElementById('muteAudio').classList.add('off');
            document.getElementById('toggleVideo').classList.add('off');
          } catch (silentErr) {
            document.getElementById('btnAllowBoth').disabled = false;
            document.getElementById('btnAllowAudio').disabled = false;
            showPermissionError(mediaErrorMessage(err));
            setPermissionStatus('Permission denied or unavailable.');
            setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.PERMISSION : 'permission', {
              callStatusText: 'Waiting for microphone permission',
              micPermissionDenied: (err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError')),
            });
            syncMediaStatus({ micPermissionDenied: true });
            return;
          }
        }
      }

      hideMediaPermissionGate();
      await startCallWithStream();
      unlockRemoteAudio().catch(() => {});
    }

    function bindMediaPermissionButtons() {
      document.getElementById('btnAllowBoth').addEventListener('click', () => requestMediaAccess(true));
      document.getElementById('btnAllowAudio').addEventListener('click', () => requestMediaAccess(false));
      document.getElementById('btnRetryMedia').addEventListener('click', () => {
        clearPermissionError();
        document.getElementById('btnAllowBoth').disabled = false;
        document.getElementById('btnAllowAudio').disabled = false;
        setPermissionStatus('Tap a button below to request access again.');
        refreshPermissionHints();
      });
      const leaveGateBtn = document.getElementById('btnLeaveFromGate');
      if (leaveGateBtn) {
        leaveGateBtn.addEventListener('click', () => leaveCallFast());
      }
    }

    const embeddedInSession = window.parent && window.parent !== window;
    if (embeddedInSession) {
      document.body.classList.add('embedded-shell');
      if (isPatient) document.body.classList.add('shell-embedded-patient');
    }
    const consultMeta = {
      providerName: <?= json_encode($provider_name) ?>,
      providerSpecialty: <?= json_encode($provider_specialty) ?>,
      providerInitials: <?= json_encode($provider_initials) ?>,
      patientName: <?= json_encode($patient_name) ?>,
      patientInitials: <?= json_encode($patient_initials) ?>,
    };
    let consultUi = null;

    function notifyParent(payload) {
      if (embeddedInSession) {
        window.parent.postMessage(payload, window.location.origin);
      }
    }

    function notifyParentCallState(statusKey, overrides) {
      if (!embeddedInSession || userRole !== 'provider') return;
      const STATUS = window.McVideoCallCore ? window.McVideoCallCore.STATUS : {};
      const connected = statusKey === 'connected' || statusKey === STATUS.CONNECTED;
      const reconnecting = statusKey === 'reconnecting' || statusKey === STATUS.RECONNECTING;
      let label = '● Connecting…';
      if (connected) label = '● LIVE';
      else if (reconnecting) label = '● Reconnecting…';
      else if (statusKey === STATUS.WAITING_PATIENT || statusKey === 'waiting_patient') label = '● Waiting for patient';
      else if (statusKey === STATUS.ENDED || statusKey === 'ended') label = '● Ended';
      notifyParent({
        type: 'medconnect:call-state',
        statusLabel: label,
        connected: connected,
        timerActive: connected,
      });
    }

    function setupSessionNavigationUi() {
      if (isPatient) return;

      const sessionLink = document.getElementById('sessionAiLink');
      const minimizeBtn = document.getElementById('minimizeVideoBtn');
      const compactBtn = document.getElementById('compactModeBtn');

      if (embeddedInSession) {
        if (sessionLink) sessionLink.style.display = 'none';
        if (minimizeBtn) minimizeBtn.style.display = 'inline-flex';
      }

      if (minimizeBtn) {
        minimizeBtn.addEventListener('click', () => {
          notifyParent({ type: 'medconnect:minimize-video', token: roomToken });
          if (consultUi && typeof consultUi.toggleFloating === 'function') {
            consultUi.toggleFloating();
          }
        });
      }

      if (compactBtn) {
        compactBtn.addEventListener('click', () => {
          const compact = document.body.classList.toggle('compact-mode');
          compactBtn.textContent = compact ? 'Full view' : 'Compact view';
          if (compact && embeddedInSession) {
            notifyParent({ type: 'medconnect:minimize-video', token: roomToken });
          }
        });
      }
    }


    function startRecording() {
      if (userRole !== 'provider') return;
      if (mediaRecorder && mediaRecorder.state === 'recording') return;
      
      console.log("Initializing PiP Recording...");
      recordedChunks = [];
      remoteAudioConnected = false;

      // Create a promise that resolves when upload finishes
      let resolveUpload;
      uploadPromise = new Promise(resolve => { resolveUpload = resolve; });

      // 1. Create a hidden canvas for compositing
      const canvas = document.createElement('canvas');
      canvas.width = 1280;
      canvas.height = 720;
      canvasContext = canvas.getContext('2d');
      
      const doctorVideo = document.getElementById('localVideo');
      const patientVideo = document.getElementById('remoteVideo');

      // 2. Composite Drawing Function (Doctor in Corner, Patient Full Screen)
      function drawFrame() {
        if (!canvasContext) return;
        
        // Background (Black)
        canvasContext.fillStyle = '#000';
        canvasContext.fillRect(0, 0, canvas.width, canvas.height);
        
        const hasPatientVideo = patientVideo.readyState >= 2 && patientVideo.srcObject;
        const hasDoctorVideo = doctorVideo.readyState >= 2;

        // Draw Patient full screen once connected. Until then, record the provider view.
        if (hasPatientVideo) {
          canvasContext.drawImage(patientVideo, 0, 0, canvas.width, canvas.height);
        } else if (hasDoctorVideo) {
          canvasContext.drawImage(doctorVideo, 0, 0, canvas.width, canvas.height);
          canvasContext.fillStyle = 'rgba(0, 0, 0, 0.42)';
          canvasContext.fillRect(0, canvas.height - 92, canvas.width, 92);
          canvasContext.fillStyle = '#fff';
          canvasContext.font = '600 28px system-ui, sans-serif';
          canvasContext.fillText('Waiting for patient to join...', 34, canvas.height - 38);
        } else {
          canvasContext.fillStyle = '#0f172a';
          canvasContext.fillRect(0, 0, canvas.width, canvas.height);
          canvasContext.fillStyle = '#94a3b8';
          canvasContext.font = '600 28px system-ui, sans-serif';
          canvasContext.fillText('Secure consultation recording', 34, canvas.height - 38);
        }
        
        // Draw Doctor PiP once the patient is the main view.
        if (hasPatientVideo && hasDoctorVideo) {
          const pipWidth = 320;
          const pipHeight = 180;
          const padding = 20;
          canvasContext.strokeStyle = '#fff';
          canvasContext.lineWidth = 2;
          canvasContext.strokeRect(canvas.width - pipWidth - padding, canvas.height - pipHeight - padding, pipWidth, pipHeight);
          canvasContext.drawImage(doctorVideo, canvas.width - pipWidth - padding, canvas.height - pipHeight - padding, pipWidth, pipHeight);
        }
      }

      // 3. Setup Canvas Stream (30 FPS)
      drawInterval = setInterval(drawFrame, 1000 / 30);
      canvasStream = canvas.captureStream(30);

      // 4. Mix Audio Tracks (provider local + patient remote into one destination)
      recordingAudioContext = new AudioContext();
      recordingAudioDestination = recordingAudioContext.createMediaStreamDestination();
      if (recordingAudioContext.state === 'suspended') {
        recordingAudioContext.resume().catch(function () {});
      }

      if (localStream.getAudioTracks().length > 0) {
        recordingAudioContext.createMediaStreamSource(localStream).connect(recordingAudioDestination);
      }

      connectRemoteAudioToRecording();

      // 5. Combine Canvas Video + Mixed Audio
      const combinedStream = new MediaStream([
        ...canvasStream.getVideoTracks(),
        ...recordingAudioDestination.stream.getAudioTracks()
      ]);
      
      const preferredType = 'video/webm;codecs=vp8,opus';
      const fallbackType = 'video/webm';
      const options = MediaRecorder.isTypeSupported(preferredType)
        ? { mimeType: preferredType }
        : (MediaRecorder.isTypeSupported(fallbackType) ? { mimeType: fallbackType } : {});

      mediaRecorder = new MediaRecorder(combinedStream, options);
      
      mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) recordedChunks.push(event.data);
      };

      mediaRecorder.onstop = async () => {
        console.log("Recording stopped. Preparing upload...");
        document.getElementById('callStatus').textContent = 'Saving Recording... Please wait.';
        document.getElementById('callStatus').style.color = '#fbbf24';
        showSavingModal('Saving consultation recording', 'Please keep this page open while the recording is uploaded.');
        
        clearInterval(drawInterval);
        const blob = new Blob(recordedChunks, { type: 'video/webm' });
        if (!blob.size) {
          console.warn("Recording blob is empty; skipping upload.");
          document.getElementById('callStatus').textContent = 'Recording was empty — nothing saved.';
          document.getElementById('callStatus').style.color = '#fca5a5';
          showSavingModal('Recording upload failed', 'The recorder produced an empty file, so nothing was saved.');
          await new Promise((r) => setTimeout(r, 2000));
          resolveUpload();
          return;
        }
        const formData = new FormData();
        formData.append('video', blob);
        formData.append('token', roomToken);

        try {
          const res = await fetch('<?= ASSET_BASE ?>/app/api/consultations/upload_recording.php', {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          if (data.success) {
            console.log("Recording uploaded successfully:", data.path);
            document.getElementById('callStatus').textContent = 'Recording saved.';
            document.getElementById('callStatus').style.color = '#86efac';
            showSavingModal('Recording saved', 'Consultation recording was uploaded successfully.');
          } else {
            const msg = (data && data.message) ? String(data.message) : 'Upload rejected by server.';
            console.error("Recording upload failed:", msg);
            document.getElementById('callStatus').textContent = 'Recording upload failed.';
            document.getElementById('callStatus').style.color = '#fca5a5';
            showSavingModal('Recording upload failed', msg);
            await new Promise((r) => setTimeout(r, 2500));
          }
        } catch (e) {
          console.error("Upload error:", e);
          document.getElementById('callStatus').textContent = 'Recording upload failed.';
          document.getElementById('callStatus').style.color = '#fca5a5';
          showSavingModal('Recording upload failed', 'Network error while uploading the consultation recording.');
          await new Promise((r) => setTimeout(r, 2500));
        } finally {
          resolveUpload(); // Signal that we're done
        }
      };

      mediaRecorder.start(1000);
      console.log("PiP Recording started.");
    }

    function connectRemoteAudioToRecording() {
      if (!recordingAudioContext || !recordingAudioDestination || remoteAudioConnected) return;
      if (recordingAudioContext.state === 'suspended') {
        recordingAudioContext.resume().catch(function () {});
      }

      let remoteStream = null;
      const remoteVideo = document.getElementById('remoteVideo');
      const remoteAudio = document.getElementById('remoteAudio');

      if (remoteVideo && remoteVideo.srcObject && remoteVideo.srcObject.getAudioTracks().length > 0) {
        remoteStream = remoteVideo.srcObject;
      } else if (remoteAudio && remoteAudio.srcObject && remoteAudio.srcObject.getAudioTracks().length > 0) {
        remoteStream = remoteAudio.srcObject;
      }

      if (!remoteStream) return;

      try {
        recordingAudioContext.createMediaStreamSource(remoteStream).connect(recordingAudioDestination);
        remoteAudioConnected = true;
        console.log('Remote participant audio connected to consultation recording.');
      } catch (err) {
        console.warn('Could not mix remote audio into recording:', err);
      }
    }

    function updateTimerDisplay() {
      const displaySeconds = Math.max(0, timeLeft);
      const mins = Math.floor(displaySeconds / 60);
      const secs = displaySeconds % 60;
      document.getElementById('timerDisplay').textContent =
        `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }

    function startTimer() {
      if (timerInterval) clearInterval(timerInterval);
      startTimer._patientExpiredMsg = false;

      timerInterval = setInterval(() => {
        if (timeLeft > 0) {
          timeLeft--;
        }

        updateTimerDisplay();

        if (timeLeft === 300 && !isPatient) {
          document.getElementById('extensionPrompt').style.display = 'flex';
        }

        if (timeLeft <= 0) {
          if (!isPatient) {
            clearInterval(timerInterval);
            timerInterval = null;
            document.getElementById('callStatus').textContent = 'Consultation time has expired. Closing the room...';
            endCall(true);
            return;
          }

          if (!startTimer._patientExpiredMsg) {
            startTimer._patientExpiredMsg = true;
            document.getElementById('callStatus').textContent =
              'Scheduled slot time has ended. You can leave or stay if your doctor extends the call.';
          }
        } else if (isPatient && startTimer._patientExpiredMsg) {
          startTimer._patientExpiredMsg = false;
          document.getElementById('callStatus').textContent = 'Connected';
        }
      }, 1000);
    }

    function showExtendToast(message, type = 'success') {
      const toast = document.getElementById('extendToast');
      toast.textContent = message;
      toast.className = 'extend-toast show ' + type;
      clearTimeout(showExtendToast._timer);
      showExtendToast._timer = setTimeout(() => {
        toast.classList.remove('show');
      }, 4500);
    }

    function applyExtension(mins, label) {
      timeLeft += mins * 60;
      document.getElementById('extensionPrompt').style.display = 'none';
      const suffix = label ? ' New end: ' + label + '.' : '';
      showExtendToast('Session extended by ' + mins + ' minutes.' + suffix, 'success');
    }

    async function requestExtension(mins = 15) {
      if (isPatient || extendingSession || consultationId <= 0) return;

      const extendBtn = document.getElementById('extendBtn');
      extendingSession = true;
      if (extendBtn) extendBtn.disabled = true;

      try {
        const res = await fetch(apiBase + '/app/api/provider/check_extension.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            consultation_id: consultationId,
            extension_mins: mins,
            csrf_token: document.body.dataset.csrf || ''
          })
        });
        const data = await res.json();

        if (data.success) {
          if (typeof data.seconds_remaining === 'number' && data.seconds_remaining > 0) {
            timeLeft = data.seconds_remaining;
          } else {
            applyExtension(data.extension_mins || mins, data.new_end_label || '');
          }
          document.getElementById('extensionPrompt').style.display = 'none';
          notifyParent({
            type: 'medconnect:session-extended',
            extension_mins: data.extension_mins || mins,
            new_end_label: data.new_end_label || ''
          });
          if (!data.seconds_remaining) {
            showExtendToast(data.message || 'Session extended.', 'success');
          } else {
            showExtendToast((data.message || 'Session extended.') + (data.new_end_label ? ' New end: ' + data.new_end_label + '.' : ''), 'success');
          }
        } else {
          showExtendToast(data.message || 'Could not extend session.', 'error');
        }
      } catch (e) {
        showExtendToast('Network error while extending session.', 'error');
      } finally {
        extendingSession = false;
        if (extendBtn) extendBtn.disabled = false;
      }
    }

    function syncTimerFromServer() {
      fetch(apiBase + '/app/api/consultations/session_timer.php?token=' + encodeURIComponent(roomToken), {
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
        mcNoLoader: true,
      })
        .then((res) => res.json())
        .then((data) => {
          if (!data.success) {
            if (!endingCall) {
              if (isPatient) {
                document.getElementById('callStatus').textContent = 'This consultation has ended.';
                leaveCallConfirmed({ reason: 'session_ended', skipApi: true });
              } else {
                document.getElementById('callStatus').textContent = 'Video session closed.';
                endCall(true);
              }
            }
            return;
          }
          if (typeof data.seconds_remaining !== 'number') return;

          const previous = timeLeft;
          timeLeft = data.seconds_remaining;
          updateTimerDisplay();

          if (timeLeft > 300) {
            document.getElementById('extensionPrompt').style.display = 'none';
          }

          if (isPatient && previous <= 0 && timeLeft > 0) {
            startTimer._patientExpiredMsg = false;
            document.getElementById('callStatus').textContent = 'Your doctor extended the session.';
            showExtendToast('Session extended. New end: ' + (data.end_label || 'updated') + '.', 'success');
          }

          if (data.slot_expired || timeLeft <= 0) {
            if (!isPatient && !endingCall) {
              document.getElementById('callStatus').textContent = 'Consultation time has expired. Closing the room...';
              endCall(true);
              return;
            }
            if (isPatient && data.consultation_status === 'completed' && !endingCall) {
              document.getElementById('callStatus').textContent = 'This consultation has ended.';
              leaveCallFast();
            }
          }
        })
        .catch(() => {});
    }

    function pingSessionKeepAlive() {
      const params = new URLSearchParams({ token: roomToken });
      if (demoMode) {
        params.set('demo_key', demoKey || '');
        params.set('demo_exp', String(demoExp || 0));
        params.set('demo_as', userRole);
      }
      fetch(apiBase + '/app/api/consultations/session_keepalive.php?' + params.toString(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-MC-No-Loader': '1' },
        mcNoLoader: true,
      }).catch(() => {});

      // Reset idle timers on other same-profile tabs (e.g. provider dashboard).
      try {
        if (typeof BroadcastChannel !== 'undefined') {
          if (!window.__mcSessionKeepAliveBus) {
            window.__mcSessionKeepAliveBus = new BroadcastChannel('medconnect-session-keepalive');
          }
          window.__mcSessionKeepAliveBus.postMessage({ type: 'activity', at: Date.now(), source: 'video_room' });
        }
      } catch (e) { /* ignore */ }
    }

    async function startCallWithStream() {
      try {
        if (!localStream) return;

        console.log('Local stream obtained.');
        if (window.McWebrtcPeerCall) {
          McWebrtcPeerCall.setLocalStream(localStream);
        } else {
          attachStreamToVideo(document.getElementById('localVideo'), localStream, { muted: true });
        }
        if (consultUi && typeof consultUi.mountVideos === 'function') consultUi.mountVideos();

        const hasVideo = localStream.getVideoTracks().length > 0;
        const hasAudio = localStream.getAudioTracks().length > 0;
        if (!hasVideo) {
          document.getElementById('toggleVideo').classList.add('off');
        }
        if (!hasAudio) {
          document.getElementById('muteAudio').classList.add('off');
        }

        syncMediaStatus();
        setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.CONNECTING : 'connecting', {
          callStatusText: userRole === 'patient'
            ? 'Connectingâ€¦'
            : 'Connectingâ€¦',
        });

        if (userRole === 'provider') {
          startRecording();
        }

        beginConnectionRetries();
        startTimer();
      } catch (err) {
        console.error('Call setup error:', err);
        showMediaPermissionGate();
        showPermissionError(mediaErrorMessage(err));
        document.getElementById('callStatus').textContent = 'Could not start call';
      }
    }

    function toggleAudio() {
      if (!localStream) return;
      if (window.McWebrtcPeerCall) {
        McWebrtcPeerCall.toggleAudio();
      } else {
        const audioTrack = localStream.getAudioTracks()[0];
        if (audioTrack) audioTrack.enabled = !audioTrack.enabled;
      }
      const audioTrack = localStream.getAudioTracks()[0];
      if (!audioTrack) {
        syncMediaStatus({ micPermissionDenied: false });
        return;
      }
      const muted = !audioTrack.enabled;
      const muteBtn = document.getElementById('muteAudio');
      if (muteBtn) {
        muteBtn.classList.toggle('off', muted);
        muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
      }
      syncMediaStatus();
      if (muteTts) muteTts.onMuteChanged(muted);
    }

    function toggleVideo() {
      if (!localStream) return;
      if (window.McWebrtcPeerCall) {
        McWebrtcPeerCall.toggleVideo();
      } else {
        const videoTrack = localStream.getVideoTracks()[0];
        if (videoTrack) videoTrack.enabled = !videoTrack.enabled;
      }
      const videoTrack = localStream.getVideoTracks()[0];
      if (!videoTrack) return;
      document.getElementById('toggleVideo').classList.toggle('off', !videoTrack.enabled);
      syncMediaStatus();
    }

    function notifyPeerLeft() {
      const payload = { type: 'peer_left', role: userRole, token: roomToken, at: Date.now() };
      sendMuteData(payload);
      if (demoMode && demoBus) {
        try {
          demoBus.postMessage(Object.assign({}, payload, { token: roomToken, role: userRole }));
        } catch (e) {}
      }
    }

    function handlePeerLeftMessage(data) {
      if (!data || data.type !== 'peer_left') return false;
      if (data.role === userRole) return true;
      if (endingCall) return true;

      if (data.role === 'patient' && userRole === 'provider') {
        remotePeerLeft = true;
        callHasRemoteStream = false;
        remoteMediaUnlocked = false;
        clearRemoteMedia();
        if (window.McWebrtcPeerCall) {
          McWebrtcPeerCall.closeCurrentCall();
        }
        setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.WAITING_PATIENT : 'waiting_patient', {
          callStatusText: 'Patient left the call',
        });
        if (consultUi && typeof consultUi.setOverlay === 'function') {
          consultUi.setOverlay(
            'Patient left the call',
            'They can rejoin from their dashboard while this session is still active.',
            true,
            { showRetry: false }
          );
        }
        return true;
      }

      if (data.role === 'provider' && userRole === 'patient') {
        document.getElementById('callStatus').textContent = 'Your healthcare provider ended the consultation.';
        leaveCallConfirmed({ reason: 'provider_left', skipApi: true });
        return true;
      }

      return true;
    }

    function stopAllCallTimers() {
      if (callInterval) {
        clearInterval(callInterval);
        callInterval = null;
      }
      if (syncTimerInterval) {
        clearInterval(syncTimerInterval);
        syncTimerInterval = null;
      }
      if (keepAliveInterval) {
        clearInterval(keepAliveInterval);
        keepAliveInterval = null;
      }
      if (demoHelloTimer) {
        clearInterval(demoHelloTimer);
        demoHelloTimer = null;
      }
      if (drawInterval) {
        clearInterval(drawInterval);
        drawInterval = null;
      }
      if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
      }
    }

    function clearRemoteMedia() {
      const remoteAudio = document.getElementById('remoteAudio');
      if (remoteAudio) {
        try { remoteAudio.pause(); } catch (e) {}
        try { remoteAudio.srcObject = null; } catch (e) {}
      }
      const remoteVideo = document.getElementById('remoteVideo');
      if (remoteVideo) {
        try { remoteVideo.srcObject = null; } catch (e) {}
      }
    }

    async function postLeaveApi() {
      try {
        await fetch(apiBase + '/app/api/consultations/end_video.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'token=' + encodeURIComponent(roomToken)
            + '&csrf_token=' + encodeURIComponent(document.body.dataset.csrf || ''),
          credentials: 'same-origin',
        });
      } catch (e) {}
    }

    function redirectAfterLeave(options) {
      options = options || {};
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({
          type: options.parentMessageType || 'medconnect:call-left',
          role: userRole,
          token: roomToken,
          reason: options.reason || '',
        }, window.location.origin);
        return;
      }
      if (options.redirectUrl) {
        window.location.href = options.redirectUrl;
        return;
      }
      if (isPatient) {
        window.location.href = apiBase + '/views/patient/consultations.php';
        return;
      }
      if (consultationId) {
        window.location.href = apiBase + '/views/provider/consultation_session.php?id=' + encodeURIComponent(consultationId) + '&followup=1';
        return;
      }
      window.location.href = apiBase + '/views/provider/dashboard.php';
    }

    function setLeaveButtonsDisabled(disabled) {
      const endBtn = document.getElementById('endCallBtn');
      const confirmBtn = document.getElementById('confirmEndBtn');
      if (endBtn) endBtn.disabled = !!disabled;
      if (confirmBtn) confirmBtn.disabled = !!disabled;
    }

    function showEndModal() {
      document.getElementById('endCallModal').classList.add('show');
    }

    function closeEndModal() {
      if (endingCall) return;
      document.getElementById('endCallModal').classList.remove('show');
    }

    function showSavingModal(title, copy) {
      if (window.MedConnectLoader) {
        window.MedConnectLoader.show({ mode: 'saving', sr: title || 'Saving.' });
      }
      document.getElementById('endModalTitle').textContent = title;
      document.getElementById('endModalCopy').textContent = copy;
      document.getElementById('endModalActions').style.display = 'none';
      document.getElementById('endCallModal').classList.add('show');
    }

    function disconnectLocalCall() {
      stopAllCallTimers();

      if (localDemoCall) {
        try { localDemoCall.stop(); } catch (e) {}
        localDemoCall = null;
      }

      if (silentAudioFallback) {
        try { silentAudioFallback.oscillator.stop(); } catch (e) {}
        try { silentAudioFallback.ctx.close(); } catch (e) {}
        silentAudioFallback = null;
      }

      try {
        if (demoBus) {
          demoBus.postMessage({ type: 'peer-bye', token: roomToken, role: userRole });
        }
      } catch (e) {}

      if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        try { mediaRecorder.stop(); } catch (e) {}
      }

      if (recordingAudioContext) {
        try { recordingAudioContext.close(); } catch (e) {}
        recordingAudioContext = null;
        recordingAudioDestination = null;
        remoteAudioConnected = false;
      }

      if (localStream) {
        if (window.McVideoCallCore) {
          window.McVideoCallCore.stopStreamTracks(localStream);
        } else {
          localStream.getTracks().forEach((track) => {
            try { track.stop(); } catch (e) {}
          });
        }
        localStream = null;
      }

      const localVideo = document.getElementById('localVideo');
      if (localVideo) {
        try { localVideo.srcObject = null; } catch (e) {}
      }

      clearRemoteMedia();

      if (window.McWebrtcPeerCall) {
        McWebrtcPeerCall.setIntentionalLeave(true);
        McWebrtcPeerCall.destroy();
      }
      peerInitialized = false;

      setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.ENDED : 'ended', {
        callStatusText: 'Consultation Ended',
      });
    }

    async function leaveCallConfirmed(options) {
      options = options || {};
      if (endingCall) {
        redirectAfterLeave(options);
        return;
      }

      endingCall = true;
      window.__mcCallEnded = true;
      setLeaveButtonsDisabled(true);
      closeEndModal();

      if (window.McWebrtcPeerCall) {
        McWebrtcPeerCall.setIntentionalLeave(true);
      }

      notifyPeerLeft();

      if (!options.skipApi) {
        await postLeaveApi();
      }

      hideMediaPermissionGate();
      disconnectLocalCall();

      if (window.MedConnectLoader && typeof window.MedConnectLoader.forceHide === 'function') {
        window.MedConnectLoader.forceHide();
      }

      if (window.McVideoRoomEnhancements) {
        if (typeof window.McVideoRoomEnhancements.markCallEnded === 'function') {
          window.McVideoRoomEnhancements.markCallEnded();
        }
        if (isPatient && typeof window.McVideoRoomEnhancements.showPostCall === 'function') {
          window.McVideoRoomEnhancements.showPostCall();
        }
      }

      redirectAfterLeave(options);
    }

    async function leaveCallFast() {
      closeEndModal();
      if (isPatient) {
        await leaveCallConfirmed();
        return;
      }
      await endCall(true);
    }

    async function endCall(skipConfirm = false) {
      const gateEl = document.getElementById('mediaPermissionGate');
      const gateOpen = gateEl && !gateEl.classList.contains('is-hidden');

      if (isPatient) {
        await leaveCallConfirmed();
        return;
      }

      if (!skipConfirm && !gateOpen) {
        showEndModal();
        return;
      }

      if (endingCall) return;
      endingCall = true;
      window.__mcCallEnded = true;
      setLeaveButtonsDisabled(true);

      const isRecording = mediaRecorder && mediaRecorder.state === 'recording';
      showSavingModal(
        isRecording ? 'Saving consultation recording' : 'Closing consultation room',
        isRecording
          ? 'The recording is still being finalized. Please wait until medConnect finishes saving it.'
          : 'Please wait while medConnect closes the secure room.'
      );

      if (window.McWebrtcPeerCall) {
        McWebrtcPeerCall.setIntentionalLeave(true);
      }

      notifyPeerLeft();

      if (mediaRecorder && mediaRecorder.state === 'recording') {
        try { mediaRecorder.requestData(); } catch (e) {}
        mediaRecorder.stop();
        if (uploadPromise) {
          console.log('Waiting for recording upload...');
          await uploadPromise;
        }
      }

      await postLeaveApi();
      disconnectLocalCall();

      if (window.MedConnectLoader && typeof window.MedConnectLoader.forceHide === 'function') {
        window.MedConnectLoader.forceHide();
      }

      if (window.McVideoRoomEnhancements) {
        if (typeof window.McVideoRoomEnhancements.markCallEnded === 'function') {
          window.McVideoRoomEnhancements.markCallEnded();
        }
        if (typeof window.McVideoRoomEnhancements.showPostCall === 'function') {
          window.McVideoRoomEnhancements.showPostCall();
        }
      }

      redirectAfterLeave({
        parentMessageType: 'medconnect:call-ended',
        reason: 'provider_ended',
      });
    }

    function confirmEndCall() {
      endCall(true);
    }

    document.getElementById('endCallModal').addEventListener('click', (event) => {
      if (event.target.id === 'endCallModal') closeEndModal();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeEndModal();
    });

    window.addEventListener('message', (event) => {
      if (event.origin !== window.location.origin || !event.data) return;
      if (event.data.type === 'medconnect:extend-session') {
        if (typeof event.data.seconds_remaining === 'number' && event.data.seconds_remaining > 0) {
          timeLeft = event.data.seconds_remaining;
        } else {
          applyExtension(event.data.extension_mins || 15, event.data.new_end_label || '');
        }
        return;
      }
      if (event.data.type === 'medconnect:shell-leave-fast' || event.data.type === 'medconnect:shell-end-call') {
        leaveCallFast();
        return;
      }
      if (event.data.type === 'medconnect:mobile-fullscreen-state' && consultUi && typeof consultUi.setMobileFullscreen === 'function') {
        consultUi.setMobileFullscreen(!!event.data.expanded);
      }
    });

    window.__mcReplaceVideoTrack = async function (newTrack) {
      const currentCall = window.McWebrtcPeerCall ? McWebrtcPeerCall.getCurrentCall() : null;
      if (!currentCall || !currentCall.peerConnection || !newTrack) return;
      try {
        const senders = currentCall.peerConnection.getSenders();
        const videoSender = senders.find((s) => s.track && s.track.kind === 'video');
        if (videoSender) {
          await videoSender.replaceTrack(newTrack);
        }
      } catch (e) {
        console.warn('replaceTrack failed:', e);
      }
    };

    function initConsultationUi() {
      if (!window.McVideoConsultationUi) return;
      consultUi = window.McVideoConsultationUi.createController({
        isPatient: isPatient,
        embedded: embeddedInSession,
        providerName: consultMeta.providerName,
        providerSpecialty: consultMeta.providerSpecialty,
        providerInitials: consultMeta.providerInitials,
        patientName: consultMeta.patientName,
        patientInitials: consultMeta.patientInitials,
        onMinimize: () => notifyParent({ type: 'medconnect:minimize-video', token: roomToken }),
        onMaximize: () => notifyParent({ type: 'medconnect:maximize-video', token: roomToken }),
      });
      consultUi.init();

      if (embeddedInSession && userRole === 'provider') {
        document.body.classList.add('compact-mode');
        const compactBtn = document.getElementById('compactModeBtn');
        if (compactBtn) compactBtn.textContent = 'Full view';
      }
    }

    syncTimerInterval = setInterval(syncTimerFromServer, 20000);
    keepAliveInterval = setInterval(pingSessionKeepAlive, 45000);
    bindMediaPermissionButtons();
    setupSessionNavigationUi();
    initConsultationUi();
    function bindLeaveButton(el) {
      if (!el || el.dataset.leaveBound) return;
      el.dataset.leaveBound = '1';
      let leaveBusy = false;
      let lastTrigger = 0;
      const triggerLeave = (event) => {
        if (event) {
          event.preventDefault();
          event.stopPropagation();
        }
        const now = Date.now();
        if (leaveBusy || (now - lastTrigger) < 500) return;
        lastTrigger = now;
        leaveBusy = true;
        Promise.resolve(endCall()).finally(() => {
          window.setTimeout(() => { leaveBusy = false; }, 600);
        });
      };
      el.addEventListener('click', triggerLeave);
    }

    window.toggleAudio = toggleAudio;
    window.toggleVideo = toggleVideo;
    window.endCall = endCall;
    window.leaveCallFast = leaveCallFast;
    bindLeaveButton(document.getElementById('endCallBtn'));

    (function bindViolationReportUi() {
      if (isPatient || !consultationId) return;
      const modal = document.getElementById('violationReportModal');
      const openBtn = document.getElementById('violationReportBtn');
      if (!modal || !openBtn) return;

      function csrf() {
        return document.body.dataset.csrf || (window.__mcVideoRoomMeta && window.__mcVideoRoomMeta.csrf) || '';
      }

      function openViolationModal() {
        modal.classList.add('show');
      }
      function closeViolationModal() {
        modal.classList.remove('show');
      }

      async function postViolation(body) {
        const res = await fetch(apiBase + '/app/api/provider/consultation_violation_report.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
          body: new URLSearchParams(body),
        });
        return res.json();
      }

      async function submitViolation(endConsultation, endOnly) {
        const reasonEl = document.getElementById('violationReason');
        const notesEl = document.getElementById('violationNotes');
        const reason = reasonEl ? String(reasonEl.value || '').trim() : '';
        const notes = notesEl ? String(notesEl.value || '').trim() : '';

        if (!endOnly && !reason) {
          alert('Please select a possible violation reason.');
          return;
        }

        const payload = {
          consultation_id: String(consultationId),
          csrf_token: csrf(),
        };
        if (endOnly) {
          payload.action = 'end_only';
          payload.reason = reason || notes || 'Provider ended consultation.';
        } else {
          payload.reason = reason;
          payload.notes = notes;
          payload.end_consultation = endConsultation ? '1' : '0';
        }

        const data = await postViolation(payload);
        if (!data || !data.success) {
          alert((data && data.message) || 'Unable to complete request.');
          return;
        }

        closeViolationModal();
        if (endConsultation || endOnly || data.ended) {
          alert(data.message || 'Consultation ended.');
          await leaveCallConfirmed({ skipApi: false });
          return;
        }
        alert(data.message || 'Report submitted.');
      }

      openBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        openViolationModal();
      });
      document.getElementById('violationCancelBtn')?.addEventListener('click', closeViolationModal);
      document.getElementById('violationSubmitBtn')?.addEventListener('click', () => submitViolation(false, false));
      document.getElementById('violationEndOnlyBtn')?.addEventListener('click', () => submitViolation(false, true));
      document.getElementById('violationReportEndBtn')?.addEventListener('click', () => submitViolation(true, false));
      modal.addEventListener('click', (e) => {
        if (e.target === modal) closeViolationModal();
      });
    })();

    dismissBootLoader();
    setupDemoBus();
    setupWebrtcEvents();
    if (!demoMode) {
      createPeer();
    } else {
      document.getElementById('callStatus').textContent = 'Allow camera & microphone to join';
    }
    document.getElementById('callStatus').textContent = 'Allow camera & microphone to join';
    if (demoMode && isLocalDevHost()) {
      const demoHint = document.getElementById('localDemoHint');
      if (demoHint) demoHint.style.display = 'block';
    }
    showMediaPermissionGate();

    document.getElementById('enableSoundBtn')?.addEventListener('click', () => {
      unlockRemoteAudio().then((ok) => {
        if (ok) {
          remoteMediaUnlocked = true;
          setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.CONNECTED : 'connected', {
            callStatusText: 'Connected â€” sound on',
          });
        }
      });
    });

    document.getElementById('retryConnectBtn')?.addEventListener('click', () => {
      callHasRemoteStream = false;
      remoteDiscoveredId = null;
      if (consultUi && typeof consultUi.setConnectionFailed === 'function') {
        consultUi.setConnectionFailed(false);
      }
      if (window.McVideoRoomEnhancements && typeof McVideoRoomEnhancements.setWaitingRetryVisible === 'function') {
        McVideoRoomEnhancements.setWaitingRetryVisible(false);
      }
      if (window.McWebrtcPeerCall) {
        McWebrtcPeerCall.resetCallState();
        McWebrtcPeerCall.closeCurrentCall();
      }
      document.getElementById('callStatus').textContent = 'Retrying connection…';
      if (demoMode) {
        const demo = ensureLocalDemoCall();
        if (demo) demo.retry();
        beginConnectionRetries();
        return;
      }
      patientMayDial = true;
      mediaJoinAt = Date.now() - 5000;
      announceDemoPeer();
      if (consultUi && typeof consultUi.setOverlay === 'function') {
        consultUi.setOverlay('Retrying connection…', 'Please wait while we reconnect to your doctor.', true);
      }
      if (!window.McWebrtcPeerCall || !McWebrtcPeerCall.isReady()) {
        if (window.McWebrtcPeerCall) {
          McWebrtcPeerCall.recreatePeer('manual-retry');
        } else {
          createPeer();
        }
      } else {
        beginConnectionRetries();
      }
    });

    ['click', 'touchstart', 'keydown'].forEach((evtName) => {
      document.addEventListener(evtName, () => {
        if (!remoteMediaUnlocked && (document.getElementById('remoteAudio')?.srcObject || document.getElementById('remoteVideo')?.srcObject)) {
          unlockRemoteAudio();
        }
      }, { passive: true });
    });

    window.addEventListener('pagehide', () => {
      try {
        if (demoBus) {
          demoBus.postMessage({ type: 'peer-bye', token: roomToken, role: userRole });
          demoBus.close();
        }
      } catch (e) {}
      if (demoHelloTimer) clearInterval(demoHelloTimer);
      try {
        if (window.McWebrtcPeerCall) McWebrtcPeerCall.destroy();
      } catch (e) {}
      if (silentAudioFallback) {
        try { silentAudioFallback.oscillator.stop(); } catch (e) {}
        try { silentAudioFallback.ctx.close(); } catch (e) {}
        silentAudioFallback = null;
      }
    });

    if (window.McMuteTts) {
      muteTts = window.McMuteTts.createController({
        userRole: userRole,
        consultationId: consultationId,
        apiBase: apiBase,
        csrfToken: document.body.dataset.csrf || '',
        demoMode: demoMode,
        demoKey: demoKey,
        demoExp: demoExp,
        demoToken: roomToken,
        demoAs: demoAs || userRole,
        getLocalStream: () => localStream,
        sendData: sendMuteData,
        notifyParent: typeof notifyParent === 'function' ? notifyParent : null,
      });
    }
  </script>
  <?php require_once VIEWS_PATH . '/partials/theme_scripts.php'; ?>
</body>
</html>
