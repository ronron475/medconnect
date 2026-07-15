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
           s.slot_date, s.start_time AS slot_start, s.end_time AS slot_end
    FROM video_sessions vs
    JOIN consultations c ON vs.consultation_id = c.id
    LEFT JOIN users p ON c.patient_id = p.id
    LEFT JOIN users d ON c.provider_id = d.id
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

$other_name = ($role === 'provider') 
    ? ($session['patient_first'] . ' ' . $session['patient_last'])
    : ($session['doctor_first'] . ' ' . $session['doctor_last']);

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
        $seconds_remaining = max(60, $slot_end_ts - time());
        $slot_end_label = date('g:i A', $slot_end_ts);
    }
} elseif (!empty($session['consult_time'])) {
    $slot_end_ts = strtotime($slot_date . ' ' . $session['consult_time']) + ($slot_minutes * 60);
    $seconds_remaining = max(60, $slot_end_ts - time());
    $slot_end_label = date('g:i A', $slot_end_ts);
}
$is_patient = ($role === 'patient');
$consultation_id = (int) ($session['consultation_id'] ?? 0);

// Persist a real CSRF token so mute-TTS / messages APIs work in production.
// Never invent a page-only token — it would fail auth_csrf_validate on send.php.
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta http-equiv="Permissions-Policy" content="camera=(self), microphone=(self), display-capture=(self)"/>
  <title>Video Consultation — medConnect</title>
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
  ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/video-mute-tts.css?v=<?= $muteTtsCssVer ?>"/>
  <script src="<?= ASSET_BASE ?>/assets/js/video-call-core.js?v=<?= $videoCoreJsVer ?>"></script>
  <script src="<?= ASSET_BASE ?>/assets/js/video-mute-tts.js?v=<?= $muteTtsJsVer ?>"></script>
  <style>
    body { margin:0; font-family:system-ui; background:#0f172a; color:#fff; height:100vh; overflow:hidden; }
    .video-container { display:grid; grid-template-columns:1fr 1fr; gap:20px; padding:20px; height:calc(100vh - 100px); }
    video { width:100%; height:100%; object-fit:cover; border-radius:16px; background:#1e293b; border:2px solid rgba(255,255,255,0.1); }
    .controls { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:rgba(30,41,59,0.8); backdrop-filter:blur(8px); padding:16px 32px; border-radius:40px; display:flex; gap:20px; border:1px solid rgba(255,255,255,0.1); }
    .btn { width:48px; height:48px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s; }
    .btn-mute { background:#334155; color:#fff; }
    .btn-mute.off { background:#ef4444; }
    .enable-sound-btn {
      position: absolute;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      z-index: 5;
      border: none;
      border-radius: 12px;
      padding: 14px 20px;
      font-weight: 800;
      font-size: 15px;
      cursor: pointer;
      background: #f59e0b;
      color: #111827;
      box-shadow: 0 10px 24px rgba(2,6,23,.45);
    }
    .enable-sound-btn[hidden] { display: none !important; }
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
    .btn-end { background:#ef4444; width:120px; border-radius:12px; color:#fff; font-weight:700; }
    .btn-end:disabled { opacity:.65; cursor:not-allowed; }
    .status-bar { position:fixed; top:20px; left:20px; display:flex; align-items:center; gap:10px; font-size:14px; background:rgba(0,0,0,0.4); padding:8px 16px; border-radius:20px; flex-wrap:wrap; max-width:calc(100vw - 40px); }
    .media-status {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-left: 6px;
    }
    .media-status span {
      font-size: 11px;
      font-weight: 700;
      padding: 3px 8px;
      border-radius: 999px;
      background: rgba(30,41,59,.85);
      border: 1px solid rgba(148,163,184,.28);
      color: #e2e8f0;
    }
    .media-status span.is-off {
      background: rgba(127,29,29,.55);
      border-color: rgba(252,165,165,.35);
      color: #fecaca;
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
    .live-dot { width:8px; height:8px; background:#ef4444; border-radius:50%; animation:pulse 2s infinite; }
    .end-modal {
      position: fixed;
      inset: 0;
      z-index: 3000;
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
    .saving-spinner {
      margin: 0 auto 16px;
    }
    @keyframes pulse { 0% { opacity:1; } 50% { opacity:0.4; } 100% { opacity:1; } }
    .extend-toast {
      position: fixed;
      top: 80px;
      left: 50%;
      transform: translateX(-50%);
      padding: 10px 18px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 700;
      z-index: 2100;
      display: none;
      max-width: min(520px, calc(100% - 32px));
      text-align: center;
      box-shadow: 0 10px 25px rgba(0,0,0,.25);
    }
    .extend-toast.show { display: block; }
    .extend-toast.success { background: #166534; color: #dcfce7; border: 1px solid #22c55e; }
    .extend-toast.error { background: #7f1d1d; color: #fee2e2; border: 1px solid #ef4444; }
    .extend-btn {
      margin-left: 10px;
      background: #fbbf24;
      color: #000;
      border: none;
      padding: 4px 12px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 800;
      cursor: pointer;
    }
    .extend-btn:disabled { opacity: .6; cursor: not-allowed; }
    .top-actions {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 2200;
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
      max-width: calc(100% - 40px);
    }
    .top-actions a,
    .top-actions button {
      height: 34px;
      padding: 0 14px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      background: rgba(5, 7, 11, 0.78);
      color: #fff;
      font-size: 11px;
      font-weight: 800;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .top-actions a:hover,
    .top-actions button:hover {
      background: rgba(1, 138, 147, 0.35);
    }
    body.compact-mode {
      overflow: auto;
      height: auto;
      min-height: 100vh;
    }
    body.compact-mode .video-container {
      height: auto;
      min-height: 38vh;
      max-height: 42vh;
      grid-template-columns: 1fr 1fr;
      padding: 12px;
    }
    body.compact-mode .status-bar {
      position: sticky;
      top: 0;
      z-index: 2100;
      margin: 12px;
      width: fit-content;
    }
    body.compact-mode .controls {
      position: sticky;
      bottom: 12px;
    }
    body.compact-mode .compact-hint {
      display: block;
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
    @media (max-width: 720px) {
      .video-container {
        grid-template-columns: 1fr;
        height: calc(100vh - 120px);
      }
      body.compact-mode .video-container {
        max-height: none;
        min-height: 280px;
      }
      .status-bar {
        left: 12px;
        right: 12px;
        top: 12px;
        flex-wrap: wrap;
        max-width: calc(100% - 24px);
      }
      .top-actions {
        top: auto;
        bottom: 92px;
        right: 12px;
        left: 12px;
        justify-content: center;
      }
    }
    .media-permission-gate {
      position: fixed;
      inset: 0;
      z-index: 100050; /* above global boot loader so Chrome demos can click Allow */
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
    .media-permission-actions button:disabled { opacity: .65; cursor: not-allowed; }
    body:not(.media-ready) .controls { display: none; }
    @media (max-width: 720px) {
      .video-container { grid-template-columns: 1fr; height: calc(100vh - 120px); }
    }
  </style>
</head>
<body
  class="<?= $is_patient ? 'role-patient' : 'role-provider' ?>"
  data-csrf="<?= htmlspecialchars($pageCsrfToken, ENT_QUOTES, 'UTF-8') ?>"
  data-asset-base="<?= htmlspecialchars(ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>"
>
<?php /* No boot loader overlay — dual Chrome tabs must be interactive immediately. */ ?>

  <div id="mediaPermissionGate" class="media-permission-gate" role="dialog" aria-modal="true" aria-labelledby="mediaPermissionTitle">
    <div class="media-permission-dialog">
      <div class="media-permission-icon" aria-hidden="true">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
      </div>
      <h2 id="mediaPermissionTitle" class="media-permission-title">Allow camera &amp; microphone</h2>
      <p class="media-permission-copy">Tap the button below, then choose <strong>Allow</strong> when your browser asks. This is required to join the video consultation.</p>
      <div id="secureContextWarning" class="media-permission-warn" style="display:none;"></div>
      <div id="mediaPermissionError" class="media-permission-error" role="alert"></div>
      <div id="mediaPermissionStatus" class="media-permission-status">Waiting for you to allow access…</div>
      <div class="media-permission-actions">
        <button type="button" class="primary" id="btnAllowBoth">Allow camera &amp; microphone</button>
        <button type="button" class="secondary" id="btnAllowAudio">Join with audio only</button>
        <button type="button" class="secondary" id="btnRetryMedia" style="display:none;">Try again</button>
      </div>
    </div>
  </div>

  <?php if (!$is_patient): ?>
  <div class="top-actions" id="topActions">
    <a href="<?= htmlspecialchars(ASSET_BASE . '/views/provider/consultation_session.php?id=' . $consultation_id) ?>" id="sessionAiLink">Session &amp; AI</a>
    <button type="button" id="minimizeVideoBtn" style="display:none;">Minimize video</button>
    <button type="button" id="compactModeBtn">Compact view</button>
  </div>
  <?php endif; ?>

  <div class="status-bar">
    <div class="live-dot"></div>
    <span id="callStatus">Connecting to secure server...</span>
    <span id="timerDisplay" style="margin-left:8px; font-family:monospace; font-weight:700; color:#fbbf24"><?= sprintf('%02d:%02d', (int) floor($seconds_remaining / 60), $seconds_remaining % 60) ?></span>
    <div class="media-status" aria-live="polite">
      <span id="mediaStatusMic">🎤 Microphone…</span>
      <span id="mediaStatusCam">📷 Camera…</span>
      <span id="mediaStatusConn">◌ Connecting</span>
      <span id="ttsTypingBadge" class="tts-typing-badge" hidden>Typing via Text-to-Speech…</span>
    </div>
    <?php if (!$is_patient): ?>
    <button type="button" class="extend-btn" id="extendBtn" onclick="requestExtension(15)">+15 min</button>
    <?php endif; ?>
  </div>

  <div id="extendToast" class="extend-toast" role="status" aria-live="polite"></div>

  <div class="video-container">
    <div style="position:relative">
      <video id="localVideo" autoplay muted playsinline></video>
      <div style="position:absolute; bottom:12px; left:12px; background:rgba(0,0,0,0.5); padding:4px 8px; border-radius:4px; font-size:12px">You (<?= ucfirst($role) ?>)</div>
    </div>
    <div style="position:relative">
      <video id="remoteVideo" autoplay playsinline></video>
      <div style="position:absolute; bottom:12px; left:12px; background:rgba(0,0,0,0.5); padding:4px 8px; border-radius:4px; font-size:12px" id="remoteName">Waiting for <?= htmlspecialchars($other_name) ?>...</div>
      <button type="button" id="enableSoundBtn" class="enable-sound-btn" hidden>🔊 Click to enable sound</button>
    </div>
  </div>

  <?php if (!$is_patient): ?>
  <div class="compact-hint" id="compactHint">
    Use <strong>Session &amp; AI</strong> to open the consultation page with live transcript, disease suggestions, and SOAP notes.
    If the call is embedded above the AI panel, tap <strong>Minimize video</strong> or <strong>Compact view</strong>.
  </div>
  <?php endif; ?>

  <div id="extensionPrompt" style="display:none; position:fixed; top:80px; left:50%; transform:translateX(-50%); background:#fbbf24; color:#000; padding:10px 20px; border-radius:8px; font-size:13px; font-weight:700; box-shadow:0 10px 15px -3px rgba(0,0,0,0.2); z-index:2000; align-items:center; gap:12px">
    <span>5 minutes remaining. Would you like to extend?</span>
    <?php if($role === 'provider'): ?>
    <button onclick="requestExtension(15)" style="background:#000; color:#fff; border:none; padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer">Extend 15m</button>
    <?php endif; ?>
  </div>

  <div class="controls">
    <button class="btn btn-mute" id="muteAudio" onclick="toggleAudio()" title="Mute / unmute microphone" aria-pressed="false">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4M8 22h8"/></svg>
    </button>
    <button class="btn btn-mute" id="toggleVideo" onclick="toggleVideo()">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
    </button>
    <button class="btn btn-end" id="endCallBtn" onclick="endCall()"><?= $is_patient ? 'Leave Call' : 'End Consultation' ?></button>
  </div>

  <div id="muteTtsBanner" class="mute-tts-banner" aria-hidden="true" role="status">
    <?php if ($is_patient): ?>
      Your microphone is muted. Type below — the provider will hear it as speech and see the text.
    <?php else: ?>
      Your microphone is muted. Type below — the patient will hear it as speech and see the text.
    <?php endif; ?>
  </div>
  <div id="remoteMuteBanner" class="remote-mute-banner" aria-hidden="true" role="status">
    <?php if ($is_patient): ?>
      Provider microphone is muted. Wait for typed voice messages — they will play as speech here.
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

  <script>
    const roomToken = '<?= $token ?>';
    const userRole  = '<?= $role ?>';
    const isPatient = <?= $is_patient ? 'true' : 'false' ?>;
    const consultationId = <?= $consultation_id ?>;
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
    let peer = null;
    let peerReady = false;
    let peerRetryTimer = null;
    let myPeerJsId = null;
    let remoteDiscoveredId = null;
    let demoBus = null;
    let demoHelloTimer = null;
    let localStream;
    let currentCall;
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
    let speechRecognition;
    let speechRecognitionActive = false;
    let liveTranscriptBuffer = '';
    let speechRestartTimer;
    let aiChunkRecorder;
    let aiChunkTimer;
    let aiChunkActive = false;
    let aiChunkUploading = false;
    let aiServerTranscript = '';
    let pendingIncomingCall = null;
    let callInterval = null;
    let dataConn = null;
    let muteTts = null;
    let outboundCallInFlight = false;
    let remoteMediaUnlocked = false;
    let silentAudioFallback = null;
    let patientMayDial = false;
    let mediaJoinAt = 0;
    let callHasRemoteStream = false;
    let localDemoCall = null;

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
            document.getElementById('callStatus').textContent = 'Found other tab — connecting…';
          }
          // Answer their hello so both sides know each other even if one started later.
          announceDemoPeer();
          if (localStream && peerReady && !callHasRemoteStream) {
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
          document.getElementById('callStatus').textContent = 'Connected — click Enable Sound';
        }
      });
      document.getElementById('remoteName').textContent = '<?= htmlspecialchars($other_name) ?>';
      setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.CONNECTED : 'connected', {
        callStatusText: 'Connected',
      });
      syncMediaStatus({ connectionLabel: '● Good Connection', connectionState: 'connected' });
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
      if (dataConn && dataConn.open) {
        try {
          dataConn.send(payload);
          sent = true;
        } catch (e) {
          console.warn('Data channel send failed:', e);
        }
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

    function wireDataConnection(conn) {
      if (!conn) return;
      // Prefer an open connection; ignore duplicate closed ones.
      if (dataConn && dataConn.open && dataConn !== conn) {
        try { conn.close(); } catch (e) {}
        return;
      }
      dataConn = conn;
      conn.on('open', () => {
        console.log('Peer data channel open:', conn.peer);
        // Re-announce mute once the live data channel is ready (production + demo).
        if (muteTts && typeof muteTts.syncMuteStateToPeer === 'function') {
          muteTts.syncMuteStateToPeer();
        }
      });
      conn.on('data', (data) => {
        if (muteTts) muteTts.handleIncomingData(data);
      });
      conn.on('close', () => {
        if (dataConn === conn) dataConn = null;
      });
      conn.on('error', (err) => console.warn('Data channel error:', err));
    }

    function openDataChannel() {
      if (!peerReady || endingCall || !peer) return;
      if (dataConn && dataConn.open) return;
      const target = remotePeerId();
      if (!target) return;
      if (demoMode && !remoteDiscoveredId) return;
      // Production: either side may open so mute TTS / mute_state still works if provider connect failed.
      // Demo: wait until discovery, then either side may open.
      try {
        const conn = peer.connect(target, { reliable: true });
        wireDataConnection(conn);
      } catch (e) {
        console.warn('Could not open data channel:', e);
      }
    }

    function onPeerOpen(id) {
      console.log('Peer open with ID:', id);
      myPeerJsId = id;
      peerReady = true;
      announceDemoPeer();
      openDataChannel();
      if (!localStream) {
        setPermissionStatus('Connected — tap below to allow camera and microphone.');
        setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.PERMISSION : 'permission', {
          callStatusText: 'Allow camera & microphone to join',
        });
      } else {
        beginConnectionRetries();
      }
    }

    function recreatePeer(reason) {
      console.warn('Recreating PeerJS connection:', reason || 'retry');
      peerReady = false;
      myPeerJsId = null;
      outboundCallInFlight = false;
      if (peerRetryTimer) {
        clearTimeout(peerRetryTimer);
        peerRetryTimer = null;
      }
      try {
        if (peer && !peer.destroyed) peer.destroy();
      } catch (e) {}
      peer = null;
      peerRetryTimer = setTimeout(() => createPeer(), 1200);
    }

    function createPeer() {
      if (peer && !peer.destroyed) {
        try { peer.destroy(); } catch (e) {}
      }
      peerReady = false;
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
      // Demo: auto PeerJS id + BroadcastChannel discovery (avoids unavailable-id / dead custom ids).
      // Production: stable role-token ids so patient/provider can find each other without a bus.
      peer = demoMode ? new Peer(peerOptions) : new Peer(peerId, peerOptions);

      // Register immediately — Chrome dual-tab races if the other side dials first.
      peer.on('open', onPeerOpen);
      peer.on('connection', (conn) => wireDataConnection(conn));
      peer.on('call', (call) => {
        console.log('Incoming call from:', call.peer);
        if (!localStream) {
          pendingIncomingCall = call;
          setPermissionStatus('Other participant is waiting — allow camera/microphone to connect.');
          document.getElementById('callStatus').textContent = 'Participant ready — allow access to join';
          return;
        }
        // Glare: if we already have a live call with audio/video, ignore extras.
        if (currentCall && currentCall !== call && callHasRemoteStream && currentCall.open) {
          try { call.close(); } catch (e) {}
          return;
        }
        // Prefer answering the inbound offer over a half-open outbound dial.
        if (currentCall && currentCall !== call) {
          try { currentCall.close(); } catch (e) {}
          currentCall = null;
          outboundCallInFlight = false;
          callHasRemoteStream = false;
        }
        if (demoMode && call.peer) {
          remoteDiscoveredId = call.peer;
        }
        call.answer(localStream);
        handleCall(call);
      });
      peer.on('disconnected', () => {
        console.warn('Peer disconnected — reconnecting signaling…');
        setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.RECONNECTING : 'reconnecting');
        try {
          if (peer && !peer.destroyed) peer.reconnect();
        } catch (e) {
          recreatePeer('disconnected');
        }
      });
      peer.on('error', (err) => {
        console.error('Peer error:', err);
        const type = err && err.type ? err.type : '';
        if (type === 'unavailable-id') {
          document.getElementById('callStatus').textContent = 'Signaling ID busy — retrying…';
          recreatePeer('unavailable-id');
          return;
        }
        if (type === 'network' || type === 'server-error' || type === 'socket-error' || type === 'socket-closed') {
          document.getElementById('callStatus').textContent = 'Signaling reconnecting…';
          recreatePeer(type);
          return;
        }
        if (type === 'peer-unavailable') {
          if (demoMode) {
            // Other tab PeerJS id not registered yet — keep discovering via BroadcastChannel.
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
        return;
      }
      if (overrides.callStatusText) {
        document.getElementById('callStatus').textContent = overrides.callStatusText;
      }
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
      return !!(currentCall && currentCall.open);
    }

    function hasActiveOrPendingCall() {
      return !!(currentCall || outboundCallInFlight || pendingIncomingCall);
    }

    function flushPendingCall() {
      if (!pendingIncomingCall || !localStream) return;
      const call = pendingIncomingCall;
      pendingIncomingCall = null;
      try {
        if (call.open === false && typeof call.close === 'function') {
          const pc = call.peerConnection;
          if (pc && (pc.connectionState === 'closed' || pc.connectionState === 'failed')) {
            console.warn('Discarding stale pending call; waiting for redial');
            return;
          }
        }
      } catch (e) {}
      console.log('Answering queued call from:', call.peer);
      try {
        call.answer(localStream);
        handleCall(call);
      } catch (e) {
        console.warn('Could not answer pending call:', e);
      }
    }

    function startCall() {
      if (demoMode) return; // demo uses McDemoLocalWebrtc
      if (!peerReady || !localStream || endingCall || !peer) return;
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
              ? 'Secure room active — connecting to patient...'
              : 'Online — connecting to doctor...',
          }
        );
      }

      if (currentCall && (!currentCall.open || !callHasRemoteStream)) {
        try { currentCall.close(); } catch (e) {}
        currentCall = null;
        outboundCallInFlight = false;
      }
      if (outboundCallInFlight) return;
      if (pendingIncomingCall) {
        flushPendingCall();
        return;
      }

      const targetId = remotePeerId();
      console.log('Attempting to call:', targetId);
      outboundCallInFlight = true;
      let call = null;
      try {
        call = peer.call(targetId, localStream);
      } catch (e) {
        console.warn('peer.call failed:', e);
        outboundCallInFlight = false;
        return;
      }
      if (call) {
        handleCall(call);
        setTimeout(() => {
          if (currentCall === call && !callHasRemoteStream) {
            outboundCallInFlight = false;
            try { call.close(); } catch (e) {}
            if (currentCall === call) currentCall = null;
          }
        }, 8000);
      } else {
        outboundCallInFlight = false;
      }
    }

    function beginConnectionRetries() {
      mediaJoinAt = Date.now();

      // Chrome dual-tab demo: local WebRTC over HTTP signaling relay.
      if (demoMode) {
        if (!window.McDemoLocalWebrtc) {
          document.getElementById('callStatus').textContent = 'Demo script missing — hard refresh (Ctrl+F5)';
          return;
        }
        if (!demoKey) {
          document.getElementById('callStatus').textContent = 'Missing demo key — reopen from demo launcher';
          return;
        }
        const demo = ensureLocalDemoCall();
        if (demo) {
          peerReady = true;
          dismissBootLoader();
          console.log('[medConnect demo] starting local WebRTC as', userRole, 'token', roomToken.slice(0, 8), 'apiBase', apiBase);
          demo.start();
          document.getElementById('callStatus').textContent = userRole === 'provider'
            ? 'Waiting for Patient tab…'
            : 'Waiting for Provider tab…';
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
        setPermissionStatus('Browser permission state — ' + states.join(' · '));
      } catch (e) {
        setPermissionStatus('Tap a button below, then allow access in the browser prompt.');
      }
    }

    function hideMediaPermissionGate() {
      const gate = document.getElementById('mediaPermissionGate');
      if (gate) gate.classList.add('is-hidden');
      document.body.classList.add('media-ready');
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
              window.location.protocol + '//' + window.location.host + '</code>). Deploy with SSL or use an HTTPS URL — then tap Allow again.'
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
        tips += '<li>Brave: Shields off → Site settings → Camera &amp; Microphone → Allow.</li>';
        tips += '<li>Phone settings → Apps → Browser → Permissions.</li>';
      } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        tips += '<li>No camera/mic found on this device.</li>';
        tips += '<li>Try <strong>Audio only</strong> or another device.</li>';
      } else if (name === 'NotReadableError' || name === 'TrackStartError') {
        tips += '<li>Another app may be using the camera (Zoom, Messenger, etc.). Close it and retry.</li>';
      } else if (name === 'SecurityError' || name === 'NotSupportedError') {
        tips += '<li>Use <strong>HTTPS</strong> or <code>localhost</code> — HTTP on a phone IP often cannot use camera.</li>';
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
      setPermissionStatus(videoEnabled ? 'Requesting camera and microphone…' : 'Requesting microphone…');
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
            setPermissionStatus('Camera blocked — joined with audio only.');
          } catch (audioErr) {
            // Chrome dual-tab: camera/mic may be locked by the other tab. Join with silent track so PeerJS can still connect.
            try {
              localStream = await createSilentMediaStream();
              setPermissionStatus('Mic busy in the other tab — joined with silent audio so the call can connect. Use mute TTS to type.');
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
            setPermissionStatus('Microphone unavailable — joined with silent audio. Mute TTS still works for typed voice.');
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
    }

    const embeddedInSession = window.parent && window.parent !== window;

    function notifyParent(payload) {
      if (embeddedInSession) {
        window.parent.postMessage(payload, window.location.origin);
      }
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

    function postTranscriptStatus(status, message) {
      notifyParent({
        type: 'medconnect:transcript-status',
        role: userRole,
        token: roomToken,
        status,
        message
      });
    }

    function postTranscriptUpdate() {
      notifyParent({
        type: 'medconnect:transcript-update',
        role: userRole,
        token: roomToken,
        transcript: liveTranscriptBuffer.trim()
      });
    }

    function startLiveTranscriptCapture() {
      if (userRole !== 'provider' || speechRecognitionActive) return;

      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SpeechRecognition) {
        postTranscriptStatus('unsupported', 'Live browser transcript is not supported here. You can still paste notes manually.');
        return;
      }

      speechRecognition = new SpeechRecognition();
      speechRecognition.continuous = true;
      speechRecognition.interimResults = true;
      speechRecognition.lang = 'en-PH';
      speechRecognitionActive = true;

      speechRecognition.onstart = () => {
        postTranscriptStatus('listening', 'Listening to the consultation audio.');
      };

      speechRecognition.onresult = (event) => {
        let finalText = '';
        let interimText = '';

        for (let i = event.resultIndex; i < event.results.length; i++) {
          const text = event.results[i][0].transcript.trim();
          if (event.results[i].isFinal) {
            finalText += text + ' ';
          } else {
            interimText += text + ' ';
          }
        }

        if (finalText) {
          liveTranscriptBuffer = (liveTranscriptBuffer + ' ' + finalText).replace(/\s+/g, ' ').trim();
        }

        notifyParent({
          type: 'medconnect:transcript-update',
          role: userRole,
          token: roomToken,
          transcript: liveTranscriptBuffer.trim(),
          interim: interimText.trim()
        });
      };

      speechRecognition.onerror = (event) => {
        postTranscriptStatus('error', 'Live transcript paused: ' + (event.error || 'speech recognition error') + '.');
      };

      speechRecognition.onend = () => {
        if (!speechRecognitionActive || endingCall) return;
        clearTimeout(speechRestartTimer);
        speechRestartTimer = setTimeout(() => {
          try { speechRecognition.start(); } catch (e) {}
        }, 800);
      };

      try {
        speechRecognition.start();
      } catch (e) {
        speechRecognitionActive = false;
        postTranscriptStatus('error', 'Could not start live transcript capture.');
      }
    }

    function stopLiveTranscriptCapture() {
      speechRecognitionActive = false;
      clearTimeout(speechRestartTimer);
      if (speechRecognition) {
        try { speechRecognition.stop(); } catch (e) {}
      }
      postTranscriptUpdate();
      postTranscriptStatus('stopped', 'Live transcript capture stopped.');
    }

    function mergeLiveTranscript(text) {
      const chunk = String(text || '').trim();
      if (!chunk) return;
      aiServerTranscript = (aiServerTranscript + ' ' + chunk).replace(/\s+/g, ' ').trim();
      liveTranscriptBuffer = aiServerTranscript;
      postTranscriptUpdate();
    }

    function postAiAnalysis(data) {
      notifyParent({
        type: 'medconnect:ai-analysis',
        role: userRole,
        token: roomToken,
        data
      });
    }

    function startAiLiveChunking() {
      if (userRole !== 'provider' || aiChunkActive) return;
      if (!recordingAudioDestination || !recordingAudioDestination.stream.getAudioTracks().length) return;
      if (!window.MediaRecorder) {
        postTranscriptStatus('unsupported', 'Live AI audio chunking is not supported in this browser.');
        return;
      }

      aiChunkActive = true;
      postTranscriptStatus('listening', 'Live AI is listening and sending audio chunks to Faster-Whisper.');

      const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
        ? 'audio/webm;codecs=opus'
        : (MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '');

      const captureChunk = () => {
        if (!aiChunkActive || endingCall || aiChunkUploading) return;

        const chunks = [];
        try {
          aiChunkRecorder = new MediaRecorder(
            recordingAudioDestination.stream,
            mimeType ? { mimeType } : undefined
          );
        } catch (e) {
          postTranscriptStatus('error', 'Could not start live AI audio chunk.');
          return;
        }

        aiChunkRecorder.ondataavailable = (event) => {
          if (event.data && event.data.size > 0) chunks.push(event.data);
        };

        aiChunkRecorder.onstop = async () => {
          if (!chunks.length || !aiChunkActive) return;
          aiChunkUploading = true;
          const blob = new Blob(chunks, { type: mimeType || 'audio/webm' });
          const formData = new FormData();
          formData.append('token', roomToken);
          formData.append('audio', blob, 'live_audio.webm');

          try {
            const response = await fetch('<?= ASSET_BASE ?>/app/api/ai/transcribe_chunk.php', {
              method: 'POST',
              body: formData
            });
            const result = await response.json();
            if (result.success && result.data) {
              mergeLiveTranscript(result.data.hiligaynon_transcript || '');
              postAiAnalysis(result.data);
              postTranscriptStatus('listening', 'Live AI is analyzing the consultation.');
            } else if (result.message) {
              postTranscriptStatus('error', result.message);
            }
          } catch (e) {
            postTranscriptStatus('error', 'Live AI could not reach the transcription service.');
          } finally {
            aiChunkUploading = false;
          }
        };

        aiChunkRecorder.start();
        setTimeout(() => {
          if (aiChunkRecorder && aiChunkRecorder.state === 'recording') {
            try { aiChunkRecorder.stop(); } catch (e) {}
          }
        }, 12000);
      };

      captureChunk();
      aiChunkTimer = setInterval(captureChunk, 14000);
    }

    function stopAiLiveChunking() {
      aiChunkActive = false;
      clearInterval(aiChunkTimer);
      if (aiChunkRecorder && aiChunkRecorder.state === 'recording') {
        try { aiChunkRecorder.stop(); } catch (e) {}
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

      // 4. Mix Audio Tracks
      recordingAudioContext = new AudioContext();
      recordingAudioDestination = recordingAudioContext.createMediaStreamDestination();
      
      if (localStream.getAudioTracks().length > 0) {
        recordingAudioContext.createMediaStreamSource(localStream).connect(recordingAudioDestination);
      }
      
      connectRemoteAudioToRecording();
      startAiLiveChunking();

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
          } else {
            console.error("Recording upload failed:", data.message || data);
          }
        } catch (e) {
          console.error("Upload error:", e);
        } finally {
          resolveUpload(); // Signal that we're done
        }
      };

      mediaRecorder.start(1000);
      console.log("PiP Recording started.");
    }

    function connectRemoteAudioToRecording() {
      if (!recordingAudioContext || !recordingAudioDestination || remoteAudioConnected) return;

      const remoteVideo = document.getElementById('remoteVideo');
      if (remoteVideo.srcObject && remoteVideo.srcObject.getAudioTracks().length > 0) {
        recordingAudioContext.createMediaStreamSource(remoteVideo.srcObject).connect(recordingAudioDestination);
        remoteAudioConnected = true;
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
          if (!data.success || typeof data.seconds_remaining !== 'number') return;

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
        attachStreamToVideo(document.getElementById('localVideo'), localStream, { muted: true });

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
            ? 'Connecting…'
            : 'Connecting…',
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

    function handleCall(call) {
      if (currentCall === call) return;
      if (currentCall && currentCall.open && callHasRemoteStream && currentCall.peer === call.peer) return;
      if (currentCall && currentCall !== call) {
        try { currentCall.close(); } catch (e) {}
      }

      currentCall = call;
      outboundCallInFlight = false;
      callHasRemoteStream = false;
      console.log('Handling call with:', call.peer);

      call.on('stream', remoteStream => {
        console.log('Remote stream received from:', call.peer);
        callHasRemoteStream = true;
        if (callInterval) {
          clearInterval(callInterval);
          callInterval = null;
        }

        const audioTracks = remoteStream.getAudioTracks ? remoteStream.getAudioTracks() : [];
        audioTracks.forEach((track) => {
          track.enabled = true;
        });
        console.log('Remote audio tracks:', audioTracks.length, audioTracks.map((t) => ({
          enabled: t.enabled,
          muted: t.muted,
          readyState: t.readyState,
        })));

        if (!audioTracks.length) {
          document.getElementById('callStatus').textContent = 'Connected — remote mic missing. Ask other tab to rejoin with audio.';
        }

        attachRemoteCallStream(remoteStream).then((ok) => {
          if (!ok) {
            showEnableSoundButton(true);
            document.getElementById('callStatus').textContent = 'Connected — click Enable Sound';
          }
        });
        document.getElementById('remoteName').textContent = '<?= htmlspecialchars($other_name) ?>';
        setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.CONNECTED : 'connected', {
          callStatusText: 'Connected',
        });
        syncMediaStatus({ connectionLabel: '● Good Connection', connectionState: 'connected' });
        const tip = document.getElementById('demoConnectTip');
        if (tip) tip.style.display = 'none';
        connectRemoteAudioToRecording();

        // Chrome often needs one gesture to start remote audio even after getUserMedia.
        setTimeout(() => unlockRemoteAudio(), 200);
        setTimeout(() => unlockRemoteAudio(), 1000);

        if (userRole === 'provider' && (!mediaRecorder || mediaRecorder.state === 'inactive')) {
          startRecording();
        }
        openDataChannel();
        if (muteTts && typeof muteTts.syncMuteStateToPeer === 'function') {
          muteTts.syncMuteStateToPeer();
        }
      });

      call.on('close', () => {
        console.log('Call closed with:', call.peer);
        if (currentCall === call) {
          currentCall = null;
        }
        outboundCallInFlight = false;
        callHasRemoteStream = false;
        remoteMediaUnlocked = false;
        showEnableSoundButton(false);
        if (!endingCall && localStream && !callInterval) {
          setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.RECONNECTING : 'reconnecting', {
            callStatusText: 'Reconnecting…',
          });
          beginConnectionRetries();
        }
      });

      call.on('error', err => {
        console.error('Call error:', err);
        outboundCallInFlight = false;
        if (currentCall === call && !callHasRemoteStream) {
          currentCall = null;
        }
      });
    }

    function toggleAudio() {
      if (!localStream) return;
      const audioTrack = localStream.getAudioTracks()[0];
      if (!audioTrack) {
        syncMediaStatus({ micPermissionDenied: false });
        return;
      }
      audioTrack.enabled = !audioTrack.enabled;
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
      const videoTrack = localStream.getVideoTracks()[0];
      if (!videoTrack) return;
      videoTrack.enabled = !videoTrack.enabled;
      document.getElementById('toggleVideo').classList.toggle('off', !videoTrack.enabled);
      syncMediaStatus();
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
      stopAiLiveChunking();
      stopLiveTranscriptCapture();
      if (localDemoCall) {
        try { localDemoCall.stop(); } catch (e) {}
        localDemoCall = null;
      }
      if (localStream) {
        if (window.McVideoCallCore) {
          window.McVideoCallCore.stopStreamTracks(localStream);
        } else {
          localStream.getTracks().forEach(track => track.stop());
        }
        localStream = null;
      }
      const remoteAudio = document.getElementById('remoteAudio');
      if (remoteAudio) {
        try { remoteAudio.srcObject = null; } catch (e) {}
      }
      const remoteVideo = document.getElementById('remoteVideo');
      if (remoteVideo) {
        try { remoteVideo.srcObject = null; } catch (e) {}
      }
      if (dataConn) {
        try { dataConn.close(); } catch (e) {}
        dataConn = null;
      }
      if (currentCall) {
        try { currentCall.close(); } catch (e) {}
        currentCall = null;
      }
      if (callInterval) {
        clearInterval(callInterval);
        callInterval = null;
      }
      clearInterval(timerInterval);
      setCallPhase(window.McVideoCallCore ? window.McVideoCallCore.STATUS.ENDED : 'ended', {
        callStatusText: 'Call Ended',
      });
    }

    async function leaveCallConfirmed() {
      if (endingCall) return;
      endingCall = true;
      document.getElementById('endCallBtn').disabled = true;
      document.getElementById('confirmEndBtn').disabled = true;
      disconnectLocalCall();

      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'medconnect:call-left', role: userRole, token: roomToken }, window.location.origin);
        return;
      }

      window.location.href = '../patient/consultations.php';
    }

    async function endCall(skipConfirm = false) {
      if (!skipConfirm) {
        showEndModal();
        return;
      }

      if (isPatient) {
        await leaveCallConfirmed();
        return;
      }

      if (endingCall) return;
      endingCall = true;
      document.getElementById('endCallBtn').disabled = true;
      document.getElementById('confirmEndBtn').disabled = true;

      const isRecording = mediaRecorder && mediaRecorder.state === 'recording';
      stopAiLiveChunking();
      showSavingModal(
        isRecording ? 'Saving consultation recording' : 'Closing consultation room',
        isRecording
          ? 'The recording is still being finalized. Please wait until medConnect finishes saving it.'
          : 'Please wait while medConnect closes the secure room.'
      );

        if (mediaRecorder && mediaRecorder.state === 'recording') {
          try { mediaRecorder.requestData(); } catch(e) {}
          mediaRecorder.stop();
          if (uploadPromise) {
            console.log("Waiting for recording upload...");
            await uploadPromise;
          }
        }
        try {
          await fetch('<?= ASSET_BASE ?>/app/api/consultations/end_video.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'token=' + encodeURIComponent(roomToken)
              + '&csrf_token=' + encodeURIComponent(document.body.dataset.csrf || '')
          });
        } catch(e) {}

        disconnectLocalCall();

        if (window.MedConnectLoader && typeof window.MedConnectLoader.forceHide === 'function') {
          window.MedConnectLoader.forceHide();
        }

        if (window.parent && window.parent !== window) {
          window.parent.postMessage({ type: 'medconnect:call-ended', role: userRole, token: roomToken }, window.location.origin);
          return;
        }

        window.location.href = '../provider/dashboard.php';
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
      }
    });

    setInterval(syncTimerFromServer, 20000);
    syncTimerFromServer();
    setInterval(pingSessionKeepAlive, 45000);
    pingSessionKeepAlive();
    bindMediaPermissionButtons();
    setupSessionNavigationUi();
    dismissBootLoader();
    setupDemoBus();
    if (!demoMode) {
      createPeer();
    } else {
      peerReady = true;
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
            callStatusText: 'Connected — sound on',
          });
        }
      });
    });

    document.getElementById('retryConnectBtn')?.addEventListener('click', () => {
      callHasRemoteStream = false;
      pendingIncomingCall = null;
      remoteDiscoveredId = null;
      outboundCallInFlight = false;
      if (currentCall) {
        try { currentCall.close(); } catch (e) {}
        currentCall = null;
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
      if (!peerReady) {
        recreatePeer('manual-retry');
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
        if (peer && !peer.destroyed) peer.destroy();
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
</body>
</html>
