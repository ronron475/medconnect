<?php
session_start();
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

if (!$token || !$role || !$uid) {
    header('Location: /index.php'); exit;
}

// Fetch session details
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
    die("Invalid or expired consultation link.");
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Video Consultation — medConnect</title>
  <?php require_once __DIR__ . '/../../bootstrap.php'; ?>
  <link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/responsive.css"/>
  <script src="https://unpkg.com/peerjs@1.5.2/dist/peerjs.min.js"></script>
  <style>
    body { margin:0; font-family:system-ui; background:#0f172a; color:#fff; height:100vh; overflow:hidden; }
    .video-container { display:grid; grid-template-columns:1fr 1fr; gap:20px; padding:20px; height:calc(100vh - 100px); }
    video { width:100%; height:100%; object-fit:cover; border-radius:16px; background:#1e293b; border:2px solid rgba(255,255,255,0.1); }
    .controls { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:rgba(30,41,59,0.8); backdrop-filter:blur(8px); padding:16px 32px; border-radius:40px; display:flex; gap:20px; border:1px solid rgba(255,255,255,0.1); }
    .btn { width:48px; height:48px; border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s; }
    .btn-mute { background:#334155; color:#fff; }
    .btn-mute.off { background:#ef4444; }
    .btn-end { background:#ef4444; width:120px; border-radius:12px; color:#fff; font-weight:700; }
    .btn-end:disabled { opacity:.65; cursor:not-allowed; }
    .status-bar { position:fixed; top:20px; left:20px; display:flex; align-items:center; gap:10px; font-size:14px; background:rgba(0,0,0,0.4); padding:8px 16px; border-radius:20px; }
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
      width: 38px;
      height: 38px;
      border-radius: 50%;
      border: 4px solid rgba(255,255,255,.18);
      border-top-color: #5eead4;
      animation: spin .8s linear infinite;
      margin: 0 auto 16px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
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
      z-index: 4000;
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
<body>

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
    <span id="timerDisplay" style="margin-left:15px; font-family:monospace; font-weight:700; color:#fbbf24"><?= sprintf('%02d:%02d', (int) floor($seconds_remaining / 60), $seconds_remaining % 60) ?></span>
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
    <button class="btn btn-mute" id="muteAudio" onclick="toggleAudio()">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4M8 22h8"/></svg>
    </button>
    <button class="btn btn-mute" id="toggleVideo" onclick="toggleVideo()">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
    </button>
    <button class="btn btn-end" id="endCallBtn" onclick="endCall()"><?= $is_patient ? 'Leave Call' : 'End Consultation' ?></button>
  </div>

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
    const apiBase = '<?= ASSET_BASE ?>';
    const peer = new Peer(userRole + '-' + roomToken);
    
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
    let peerReady = false;

    function isLocalDevHost() {
      const host = window.location.hostname;
      return host === 'localhost' || host === '127.0.0.1' || host === '[::1]';
    }

    function canUseMediaDevices() {
      return !!(navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function');
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

      if (window.isSecureContext || isLocalDevHost()) {
        warn.style.display = 'none';
        return;
      }

      warn.style.display = 'block';
      warn.innerHTML =
        '<strong>HTTPS required on phones.</strong> You are on <code>' + window.location.protocol + '//' + window.location.host + '</code>. ' +
        'Mobile browsers usually block camera/mic over plain HTTP. Use <strong>https://</strong> (e.g. mkcert on your PC) or test video on the same laptop with <code>localhost</code>. ' +
        'Brave: turn <strong>Shields off</strong> for this site after switching to HTTPS.';
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
        showPermissionError(
          '<strong>Media devices are not available.</strong> Your browser blocked access, often because this page is not secure (HTTP on a phone). Switch to HTTPS or open on this computer using <code>localhost</code>.'
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

      try {
        localStream = await navigator.mediaDevices.getUserMedia({
          video: videoEnabled ? { facingMode: 'user' } : false,
          audio: {
            echoCancellation: true,
            noiseSuppression: true
          }
        });
      } catch (err) {
        console.warn('Media request failed:', err);
        if (videoEnabled) {
          try {
            localStream = await navigator.mediaDevices.getUserMedia({ video: false, audio: true });
            setPermissionStatus('Camera blocked — joined with audio only.');
          } catch (audioErr) {
            document.getElementById('btnAllowBoth').disabled = false;
            document.getElementById('btnAllowAudio').disabled = false;
            showPermissionError(mediaErrorMessage(audioErr));
            setPermissionStatus('Permission denied or unavailable.');
            document.getElementById('callStatus').textContent = 'Waiting for camera/mic permission';
            return;
          }
        } else {
          document.getElementById('btnAllowBoth').disabled = false;
          document.getElementById('btnAllowAudio').disabled = false;
          showPermissionError(mediaErrorMessage(err));
          setPermissionStatus('Permission denied or unavailable.');
          document.getElementById('callStatus').textContent = 'Waiting for microphone permission';
          return;
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
            extension_mins: mins
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
        credentials: 'same-origin'
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

    let callInterval;
    function startCall() {
      // Only call if we have a stream and are not already in a connected call
      if (userRole === 'provider' && localStream && (!currentCall || !currentCall.open)) {
        const patientId = 'patient-' + roomToken;
        console.log('Attempting to call patient:', patientId);
        const call = peer.call(patientId, localStream);
        if (call) {
          handleCall(call);
        }
      }
    }

    async function startCallWithStream() {
      try {
        if (!localStream) return;

        console.log('Local stream obtained.');
        document.getElementById('localVideo').srcObject = localStream;

        const hasVideo = localStream.getVideoTracks().length > 0;
        const hasAudio = localStream.getAudioTracks().length > 0;
        if (!hasVideo) {
          document.getElementById('toggleVideo').classList.add('off');
        }
        if (!hasAudio) {
          document.getElementById('muteAudio').classList.add('off');
        }

        if (userRole === 'patient') {
          document.getElementById('callStatus').textContent = 'Online — Waiting for Doctor...';
          peer.on('call', call => {
            console.log('Incoming call from doctor, answering...');
            call.answer(localStream);
            handleCall(call);
          });
        } else {
          document.getElementById('callStatus').textContent = 'Secure Room Active';
          startRecording();
          startCall();
          if (callInterval) clearInterval(callInterval);
          callInterval = setInterval(startCall, 3000);
        }
        startTimer();
      } catch (err) {
        console.error('Call setup error:', err);
        showMediaPermissionGate();
        showPermissionError(mediaErrorMessage(err));
        document.getElementById('callStatus').textContent = 'Could not start call';
      }
    }

    function handleCall(call) {
      // If we already have a connected call, don't re-handle
      if (currentCall && currentCall.open && currentCall.peer === call.peer) return;
      
      currentCall = call;
      console.log("Handling call from:", call.peer);

      call.on('stream', remoteStream => {
        console.log("Remote stream received!");
        if (callInterval) {
          clearInterval(callInterval);
          callInterval = null;
        }
        document.getElementById('remoteVideo').srcObject = remoteStream;
        document.getElementById('remoteName').textContent = '<?= htmlspecialchars($other_name) ?>';
        document.getElementById('callStatus').textContent = 'Live Consultation';
        connectRemoteAudioToRecording();
        
        // Start recording once the doctor receives the patient's stream
        if (userRole === 'provider' && (!mediaRecorder || mediaRecorder.state === 'inactive')) {
          // Add remote tracks to the recording stream if possible
          startRecording();
        }
      });

      call.on('close', () => {
        console.log("Call closed.");
        if (userRole === 'provider' && !callInterval) {
           callInterval = setInterval(startCall, 3000);
        }
        document.getElementById('callStatus').textContent = 'Participant disconnected.';
      });

      call.on('error', err => {
        console.error("Call Error:", err);
      });
    }

    function toggleAudio() {
      if (!localStream) return;
      const audioTrack = localStream.getAudioTracks()[0];
      if (!audioTrack) return;
      audioTrack.enabled = !audioTrack.enabled;
      document.getElementById('muteAudio').classList.toggle('off');
    }

    function toggleVideo() {
      if (!localStream) return;
      const videoTrack = localStream.getVideoTracks()[0];
      if (!videoTrack) return;
      videoTrack.enabled = !videoTrack.enabled;
      document.getElementById('toggleVideo').classList.toggle('off');
    }

    function showEndModal() {
      document.getElementById('endCallModal').classList.add('show');
    }

    function closeEndModal() {
      if (endingCall) return;
      document.getElementById('endCallModal').classList.remove('show');
    }

    function showSavingModal(title, copy) {
      const icon = document.getElementById('endModalIcon');
      icon.className = '';
      icon.innerHTML = '<div class="saving-spinner" aria-hidden="true"></div>';
      document.getElementById('endModalTitle').textContent = title;
      document.getElementById('endModalCopy').textContent = copy;
      document.getElementById('endModalActions').style.display = 'none';
      document.getElementById('endCallModal').classList.add('show');
    }

    function disconnectLocalCall() {
      stopAiLiveChunking();
      stopLiveTranscriptCapture();
      if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
      }
      if (currentCall) {
        try { currentCall.close(); } catch (e) {}
      }
      if (callInterval) {
        clearInterval(callInterval);
        callInterval = null;
      }
      clearInterval(timerInterval);
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

      window.location.href = '../patient/dashboard.php#view-consultations';
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
            body: 'token=' + roomToken
          });
        } catch(e) {}

        disconnectLocalCall();

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
    bindMediaPermissionButtons();
    setupSessionNavigationUi();
    document.getElementById('callStatus').textContent = 'Allow camera & microphone to join';
    showMediaPermissionGate();

    peer.on('open', id => {
      console.log('Peer open with ID:', id);
      peerReady = true;
      if (!localStream) {
        setPermissionStatus('Connected — tap below to allow camera and microphone.');
      }
    });

    peer.on('error', err => {
      console.error('Peer error:', err);
      if (err.type === 'peer-unavailable') {
        document.getElementById('callStatus').textContent = 'Waiting for participant to join...';
      }
    });
  </script>
</body>
</html>
