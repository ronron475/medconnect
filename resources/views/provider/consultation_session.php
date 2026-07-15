<?php
session_start();
$active_page = 'consultation';
$page_title  = 'Tele-Consultation Session';
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require_once BASE_PATH . '/app/includes/message_deletion.php';
require_once BASE_PATH . '/app/includes/patient_health_summary.php';
require __DIR__ . '/partials/queue_helpers.php';

$consultation_id = (int)($_GET['id'] ?? 0);

if (!$consultation_id) {
    echo "Consultation ID required.";
    exit;
}

// Fetch real data
$stmt = $pdo->prepare("
    SELECT c.*, u.first_name, u.last_name,
           p.date_of_birth, p.age, p.gender, p.blood_type,
           p.allergies, p.existing_conditions, p.current_medications,
           p.status as patient_status,
           s.slot_date, s.start_time AS slot_start
    FROM consultations c
    JOIN users u ON c.patient_id = u.id
    LEFT JOIN patient_registrations p ON p.user_id = u.id OR p.email = u.email
    LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
    WHERE c.id = ? AND c.provider_id = ?
    LIMIT 1
");
$stmt->execute([$consultation_id, $_SESSION['user_id']]);
$c = $stmt->fetch();

if (!$c) {
    echo "Consultation not found or access denied.";
    exit;
}

$session_access = queue_session_access($c);
if (!$session_access['allowed']) {
    $page_title = 'Session Not Available';
    require __DIR__ . '/partials/layout_open.php';
    ?>
    <div class="mc-card" style="max-width: 640px; margin: 0 auto; padding: 28px 32px;">
      <h2 class="text-h2" style="margin-bottom: 12px;">Session Not Available</h2>
      <p style="color: var(--mc-slate-muted); line-height: 1.6; margin-bottom: 18px;">
        <?= htmlspecialchars($session_access['reason']) ?>
      </p>
      <p style="font-size: 13px; color: var(--mc-navy-dark); margin-bottom: 24px;">
        <strong>Scheduled:</strong> <?= htmlspecialchars($session_access['scheduled_label']) ?>
      </p>
      <a href="<?= ASSET_BASE ?>/views/provider/queue.php" class="mc-btn mc-btn--primary">Back to Consultation Queue</a>
    </div>
    <?php
    require __DIR__ . '/partials/layout_close.php';
    exit;
}

$page_styles = ['messages-delete.css'];
require __DIR__.'/partials/layout_open.php';

$profile = patient_registration_profile_fields($pdo, (int) $c['patient_id']);

$patient = [
    'name' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
    'initials' => strtoupper(substr($c['first_name'] ?? 'P', 0, 1) . substr($c['last_name'] ?? '', 0, 1)),
    'age' => $c['age'] ?? '—',
    'sex' => $profile['sex'],
    'blood_type' => $profile['blood_type'],
    'history' => $profile['history'],
    'allergies' => $profile['allergies'],
    'medications' => $profile['medications'],
    'triage_level' => 'N/A',
    'complaint' => $c['consult_type'] ?: 'General consultation',
];

// Fetch triage for this patient (latest)
$t_stmt = $pdo->prepare("
    SELECT level, urgency_label, chief_complaint
    FROM triage_results
    WHERE patient_id = ?
    ORDER BY assessed_at DESC
    LIMIT 1
");
$t_stmt->execute([$c['patient_id']]);
$triage = $t_stmt->fetch();
if ($triage) {
    $patient['triage_level'] = $triage['urgency_label'] ?: ($triage['level'] ?? 'N/A');
    if (!empty($triage['chief_complaint'])) {
        $patient['complaint'] = $triage['chief_complaint'];
    }
}

$session_messages = [];
try {
    consultation_messages_ensure_schema($pdo);
    $session_messages = message_fetch_consultation_messages($pdo, $consultation_id, (int)$_SESSION['user_id']);
    foreach ($session_messages as &$session_message) {
        $session_message['time'] = $session_message['time'] ?? date('M j, g:i A', strtotime($session_message['created_at']));
    }
    unset($session_message);
} catch (Exception $e) {
    error_log('Consultation session messages failed: ' . $e->getMessage());
}

// Booked slot end time (for session extension UI)
$slot_end_label = 'Not scheduled';
$slot_stmt = $pdo->prepare("
    SELECT slot_date, end_time
    FROM appointment_slots
    WHERE consultation_id = ? AND status = 'booked'
    LIMIT 1
");
$slot_stmt->execute([$consultation_id]);
$booked_slot = $slot_stmt->fetch(PDO::FETCH_ASSOC);
if ($booked_slot && !empty($booked_slot['end_time'])) {
    $slot_end_label = date('g:i A', strtotime($booked_slot['end_time']));
}

// Check for active video session
$v_stmt = $pdo->prepare("SELECT room_token FROM video_sessions WHERE consultation_id = ? AND status = 'active' LIMIT 1");
$v_stmt->execute([$consultation_id]);
$v_session = $v_stmt->fetch();
$room_token = $v_session ? $v_session['room_token'] : '';
$show_video_demo_tip = function_exists('medconnect_is_local_dev_host') && medconnect_is_local_dev_host();
$localhost_app_url = 'http://localhost' . (ASSET_BASE !== '' ? ASSET_BASE : '');
?>

<style>
.session-page {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: 22px;
    align-items: start;
}
.session-left {
    display: flex;
    flex-direction: column;
    gap: 22px;
    min-width: 0;
}
.session-side {
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.session-card {
    background: #fff;
    border: 1px solid #dce8ed;
    border-radius: 12px;
    box-shadow: 0 10px 28px rgba(1, 42, 74, 0.06);
    overflow: hidden;
}
.session-card-header {
    min-height: 58px;
    padding: 16px 20px;
    border-bottom: 1px solid #e2edf1;
    background: #f8fbfc;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
}
.session-card-title {
    display: flex;
    align-items: center;
    gap: 9px;
    color: #012a4a;
    font-size: 15px;
    font-weight: 800;
}
.session-card-body {
    padding: 20px;
}
.video-shell {
    position: relative;
    min-height: 430px;
    aspect-ratio: 16 / 9;
    background: radial-gradient(circle at 50% 40%, #13243a 0%, #05070b 52%, #000 100%);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
    transition: height 0.25s ease, min-height 0.25s ease, aspect-ratio 0.25s ease;
}
.video-shell.is-minimized {
    min-height: 0;
    aspect-ratio: auto;
    height: 220px;
}
.video-shell-tools {
    position: absolute;
    top: 16px;
    right: 16px;
    z-index: 4;
    display: none;
    gap: 8px;
}
.video-shell.is-live .video-shell-tools {
    display: flex;
}
.video-size-btn {
    height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    background: rgba(5, 7, 11, 0.78);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
}
.video-size-btn:hover {
    background: rgba(1, 138, 147, 0.35);
}
.scroll-ai-btn {
    position: fixed;
    right: 20px;
    bottom: 24px;
    z-index: 50;
    display: none;
    height: 42px;
    padding: 0 16px;
    border-radius: 999px;
    border: none;
    background: #018a93;
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 10px 24px rgba(1, 138, 147, 0.35);
}
.scroll-ai-btn.show {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.video-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: #fff;
    text-align: center;
}
.video-placeholder-icon {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.14);
    color: #5eead4;
}
.video-placeholder-title {
    font-size: 16px;
    font-weight: 800;
}
.video-placeholder-sub {
    color: rgba(255, 255, 255, 0.58);
    font-size: 13px;
    max-width: 360px;
}
.active-call {
    display: none;
    width: 100%;
    height: 100%;
}
.active-call iframe {
    width: 100%;
    height: 100%;
    border: 0;
}
.session-status {
    position: absolute;
    top: 16px;
    left: 16px;
    z-index: 3;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(5, 7, 11, 0.72);
    color: #fff;
    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
    font-size: 12px;
    border: 1px solid rgba(255, 255, 255, 0.12);
}
.session-btn {
    height: 38px;
    border-radius: 9px;
    border: 1px solid #cfdde4;
    background: #fff;
    color: #012a4a;
    padding: 0 15px;
    font: inherit;
    font-size: 12.5px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
}
.session-btn:hover {
    background: #f4f8fa;
}
.session-btn.primary {
    border-color: #018a93;
    background: #018a93;
    color: #fff;
}
.session-btn.primary:hover {
    background: #02777f;
}
.soap-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.soap-full {
    margin-top: 18px;
    padding: 16px;
    background: #f0fdfa;
    border: 1px solid #b8ece6;
    border-radius: 12px;
}
.pd-textarea {
    width: 100%;
    min-height: 112px;
    background: #fff;
    border: 1px solid #dce8ed;
    border-radius: 10px;
    color: #012a4a;
    padding: 12px;
    font-size: 13px;
    font-family: inherit;
    resize: vertical;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.pd-textarea:focus {
    border-color: #018a93;
    box-shadow: 0 0 0 3px rgba(1, 138, 147, 0.12);
}
.pd-label {
    display: block;
    font-size: 11px;
    color: #608395;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: 0.04em;
    margin-bottom: 7px;
}
.pd-input {
    width: 100%;
    height: 40px;
    background: #fff;
    border: 1px solid #dce8ed;
    border-radius: 9px;
    color: #012a4a;
    padding: 0 12px;
    font-size: 13px;
    outline: none;
}
.patient-head {
    display: flex;
    align-items: center;
    gap: 13px;
    margin-bottom: 18px;
}
.patient-avatar {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: #dff7f5;
    color: #018a93;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 18px;
}
.patient-name {
    font-weight: 850;
    color: #012a4a;
}
.patient-sub {
    font-size: 12px;
    color: #608395;
    margin-top: 3px;
}
.info-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 0;
    border-top: 1px solid #eef4f6;
    font-size: 13px;
}
.info-key {
    color: #608395;
    font-weight: 700;
}
.info-val {
    color: #012a4a;
    font-weight: 750;
    text-align: right;
}
.complaint-box {
    margin-top: 14px;
    padding: 13px;
    background: #f4f8fa;
    border: 1px solid #e2edf1;
    border-radius: 10px;
    color: #012a4a;
    font-size: 13px;
    line-height: 1.45;
}
.complaint-box strong {
    display: block;
    color: #018a93;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 5px;
}
.side-stack {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.session-chat-body {
    height: 260px;
    overflow-y: auto;
    padding: 16px;
    background: linear-gradient(180deg, #fff 0%, #f8fbfc 100%);
}
.session-chat-empty {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: #608395;
    font-size: 13px;
    padding: 24px;
}
.chat-row {
    display: flex;
    gap: 9px;
    align-items: flex-end;
    margin-bottom: 12px;
}
.chat-row.mine {
    flex-direction: row-reverse;
}
.chat-avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: linear-gradient(135deg, #018a93, #2563eb);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 900;
    flex-shrink: 0;
}
.chat-bubble {
    max-width: 230px;
    border-radius: 13px;
    padding: 10px 12px;
    font-size: 12.5px;
    line-height: 1.45;
}
.chat-bubble.patient {
    background: #fff;
    border: 1px solid #e2edf1;
    color: #334155;
    border-bottom-left-radius: 4px;
}
.chat-bubble.mine {
    background: #ccfbf1;
    border: 1px solid #99f6e4;
    color: #134e4a;
    border-bottom-right-radius: 4px;
}
.chat-bubble.is-mute-tts {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e3a8a;
}
.chat-mute-tts-badge {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #1d4ed8;
    margin-bottom: 4px;
}
.chat-mute-tts-status {
    font-size: 11px;
    color: #047857;
    margin-top: 4px;
}
.chat-mute-tts-play {
    margin-top: 6px;
    border: 1px solid #93c5fd;
    background: #fff;
    color: #1d4ed8;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 8px;
    cursor: pointer;
}
.chat-time {
    color: #608395;
    font-size: 10.5px;
    margin-top: 3px;
}
.session-chat-composer {
    display: flex;
    gap: 8px;
    padding: 12px;
    border-top: 1px solid #e2edf1;
    background: #fff;
}
.session-chat-composer input {
    flex: 1;
    min-width: 0;
    height: 38px;
    border: 1px solid #dce8ed;
    border-radius: 9px;
    padding: 0 11px;
    font: inherit;
    font-size: 12.5px;
    color: #012a4a;
}
.session-chat-alert {
    display: none;
    margin: 0 12px 10px;
    padding: 8px 10px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 750;
}
.session-chat-alert.show { display: block; }
.session-chat-alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.session-chat-alert.success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.ai-panel-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 280px;
    gap: 16px;
}
.ai-results {
    background: #f8fbfc;
    border: 1px solid #e2edf1;
    border-radius: 12px;
    padding: 14px;
    min-height: 180px;
}
.ai-chip-list {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 8px;
}
.ai-chip {
    border-radius: 999px;
    padding: 5px 10px;
    background: #e0f2fe;
    color: #075985;
    font-size: 11px;
    font-weight: 850;
    text-transform: uppercase;
}
.ai-chip.med { background: #ecfdf5; color: #047857; }
.ai-chip.urgent { background: #fee2e2; color: #b91c1c; }
.ai-triage-pill {
    display: inline-block;
    margin-top: 8px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 850;
    text-transform: uppercase;
}
.ai-triage--unknown { background: #f1f5f9; color: #64748b; }
.ai-triage--low { background: #ecfdf5; color: #047857; }
.ai-triage--moderate { background: #fef3c7; color: #b45309; }
.ai-triage--high { background: #ffedd5; color: #c2410c; }
.ai-triage--critical { background: #fee2e2; color: #b91c1c; }
.ai-disease-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
}
.ai-disease-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    background: #f8fafc;
}
.ai-disease-card strong { color: #012a4a; font-size: 13px; }
.ai-disease-card span { color: #64748b; font-size: 11px; font-weight: 700; }
.ai-summary {
    color: #425b6b;
    font-size: 13px;
    line-height: 1.5;
    margin-top: 10px;
}
.ai-live-status {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    color: #608395;
    font-size: 12px;
    font-weight: 750;
}
.ai-live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #94a3b8;
}
.ai-live-status.listening .ai-live-dot {
    background: #ef4444;
    animation: livePulse 1.4s infinite;
}
.ai-live-status.error .ai-live-dot,
.ai-live-status.unsupported .ai-live-dot {
    background: #f59e0b;
}
@keyframes livePulse {
    0%, 100% { opacity: 1; }
    50% { opacity: .35; }
}
@media (max-width: 1180px) {
    .session-page {
        grid-template-columns: 1fr;
    }
    .session-side {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 1100px) {
    .session-page {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .soap-grid,
    .ai-panel-grid,
    .session-side {
        grid-template-columns: 1fr;
    }
    .video-shell {
        min-height: 240px;
    }
    .session-card-header {
        align-items: flex-start;
        flex-direction: column;
    }
    .session-btn {
        min-height: 44px;
    }
}
.video-demo-tip {
    margin-top: 16px;
    max-width: 420px;
    text-align: left;
    padding: 14px 16px;
    border-radius: 12px;
    background: rgba(1, 138, 147, 0.12);
    border: 1px solid rgba(1, 138, 147, 0.28);
    color: #cbd5e1;
    font-size: 12px;
    line-height: 1.55;
}
.video-demo-tip strong { color: #e2e8f0; }
.video-demo-tip ol {
    margin: 8px 0 0 18px;
    padding: 0;
}
.video-demo-tip li { margin-bottom: 4px; }
.video-demo-tip code {
    background: rgba(15, 23, 42, 0.55);
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 11px;
}
.video-demo-link {
    margin: 12px 16px 0;
    display: none;
    max-width: 100%;
    text-align: left;
}
.video-shell:has(#activeCallUI[style*="block"]) .video-demo-link.is-visible,
.video-demo-link.is-visible { display: block; }
.video-demo-link label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #94a3b8;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.video-demo-link-row {
    display: flex;
    gap: 8px;
}
.video-demo-link-row input {
    flex: 1;
    min-width: 0;
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.35);
    background: rgba(15, 23, 42, 0.65);
    color: #e2e8f0;
    font-size: 11px;
}
</style>

<div class="session-page">
    
    <!-- LEFT: Video Panel & SOAP Notes -->
    <div class="session-left">
        
        <!-- VIDEO INTERFACE -->
        <div class="video-shell" id="videoInterface">
            <div class="video-shell-tools">
                <button type="button" class="video-size-btn" id="toggleVideoSizeBtn" onclick="toggleVideoShellSize()">Minimize video</button>
                <button type="button" class="video-size-btn" id="scrollToAiBtn" onclick="scrollToAiPanel()">View AI below</button>
            </div>
            <div id="videoPlaceholder" class="video-placeholder">
                <div class="video-placeholder-icon"><?= icon('video') ?></div>
                <div class="video-placeholder-title">Secure Video Consultation</div>
                <div class="video-placeholder-sub">
                  Step 1: open this session from the queue.<br>
                  Step 2: click <strong>Start Video Consultation</strong> (creates the live room).<br>
                  Step 3: patient sees <strong>Join Call</strong> automatically and enters the same room.
                </div>
                <?php if ($show_video_demo_tip): ?>
                <div class="video-demo-tip" id="videoDemoTip">
                    <strong>Local demo — 2 tabs on this laptop</strong>
                    <ol>
                        <li><strong>Tab 1 (here):</strong> Click <strong>Start Video Consultation</strong> (provider creates the live room).</li>
                        <li><strong>Tab 2:</strong> Incognito → log in as <strong>patient</strong> → Dashboard / My Sessions.</li>
                        <li>Patient button stays <strong>Waiting for Provider</strong> until you start, then becomes <strong>Join Call</strong>.</li>
                        <li>Wait until both sides show <strong>Live Consultation — Connected</strong> before speaking.</li>
                        <li>One webcam: provider uses camera; patient can use <strong>Join with audio only</strong>.</li>
                    </ol>
                </div>
                <?php endif; ?>
                <button onclick="startVideoCall()" class="session-btn primary"><?= icon_sm('video') ?> Start Video Consultation</button>
            </div>
            
            <?php if ($show_video_demo_tip): ?>
            <div class="video-demo-link" id="patientJoinLinkBox">
                <label for="patientJoinLinkInput">Patient join link (paste in Incognito tab)</label>
                <div class="video-demo-link-row">
                    <input type="text" id="patientJoinLinkInput" readonly value="<?= $room_token ? htmlspecialchars(BASE_URL . '/views/consultation/video_room.php?token=' . $room_token) : '' ?>">
                    <button type="button" class="session-btn" onclick="copyPatientJoinLink()">Copy</button>
                </div>
                <button type="button" class="session-btn" id="openProviderVideoTabBtn" style="margin-top:8px;display:none;" onclick="openProviderVideoTab()">Open provider video in full tab</button>
            </div>
            <?php endif; ?>
            
            <!-- Active Call UI (hidden initially) -->
            <div id="activeCallUI" class="active-call">
                <iframe id="videoFrame" src="" allow="camera *; microphone *; display-capture *; autoplay *; fullscreen *" allowfullscreen></iframe>
            </div>

            <!-- Session Status Overlay -->
            <div class="session-status">
                <span id="callStatusIndicator" style="color: #64748b; margin-right: 5px;">● OFFLINE</span> <span id="sessionTimer">00:00:00</span>
            </div>
        </div>

        <!-- AI NLP ASSISTANT -->
        <div class="session-card" id="aiAssistantCard">
            <div class="session-card-header">
                <div class="session-card-title"><?= icon('scan') ?> AI Transcript Assistant</div>
                <button type="button" class="session-btn primary" id="analyzeTranscriptBtn">Analyze Transcript</button>
            </div>
            <div class="session-card-body">
                <div class="ai-panel-grid">
                    <div>
                        <label class="pd-label">Hiligaynon / Mixed Transcript</label>
                        <textarea id="aiTranscriptInput" class="pd-textarea" style="min-height:180px" placeholder="Paste live transcript or consultation notes here. Example: May hilanat kag ubo, nag inom sang paracetamol."></textarea>
                        <div id="aiLiveStatus" class="ai-live-status">
                            <span class="ai-live-dot"></span>
                            <span id="aiLiveStatusText">Start the video call to capture live transcript when supported.</span>
                        </div>
                        <div id="aiInterimTranscript" class="text-xs text-muted" style="margin-top:6px; min-height:16px;"></div>
                    </div>
                    <div class="ai-results">
                        <div class="pd-label">Extracted Symptoms</div>
                        <div id="aiSymptoms" class="ai-chip-list"><span class="text-xs text-muted">No analysis yet.</span></div>
                        <div class="pd-label" style="margin-top:14px">Mentioned Medicines</div>
                        <div id="aiMedicines" class="ai-chip-list"><span class="text-xs text-muted">No medicines detected.</span></div>
                        <div class="pd-label" style="margin-top:14px">Urgent Cues</div>
                        <div id="aiUrgent" class="ai-chip-list"><span class="text-xs text-muted">None detected.</span></div>
                        <div class="pd-label" style="margin-top:14px">Triage Level</div>
                        <div id="aiTriage" class="ai-triage-pill ai-triage--unknown">Not assessed</div>
                        <div class="pd-label" style="margin-top:14px">Possible Conditions (ML)</div>
                        <div id="aiDiseases" class="ai-disease-list"><span class="text-xs text-muted">Run analysis to see suggestions.</span></div>
                    </div>
                </div>
                <div style="margin-top:14px">
                    <label class="pd-label">English Transcript</label>
                    <div id="aiEnglishTranscript" class="complaint-box" style="margin-top:0">Waiting for analysis.</div>
                </div>
                <div style="margin-top:14px">
                    <label class="pd-label">Suggested Clinical Summary</label>
                    <div id="aiSummary" class="ai-summary">AI suggestions will appear here. Doctor must verify before using in the medical record.</div>
                    <button type="button" class="session-btn" id="copyAiToSoapBtn" style="margin-top:12px">Copy Summary to Assessment</button>
                </div>
    </div>
</div>

<button type="button" class="scroll-ai-btn" id="floatingScrollAiBtn" onclick="scrollToAiPanel()">View AI Assistant</button>

        <!-- SOAP ENCODING FORM -->
        <div class="session-card">
            <div class="session-card-header">
                <div class="session-card-title"><?= icon('file') ?> Clinical Documentation (SOAP)</div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button class="session-btn primary" onclick="saveSOAP()">Save Progress</button>
                    <button class="session-btn" onclick="document.getElementById('soapForm').reset()">Clear</button>
                </div>
            </div>
            <div class="session-card-body">
                <form id="soapForm">
                    <input type="hidden" name="consultation_id" value="<?= $consultation_id ?>">
                    <input type="hidden" name="patient_id" value="<?= (int)$c['patient_id'] ?>">
                    
                    <div class="soap-grid">
                        <div>
                            <label class="pd-label">Subjective</label>
                            <textarea name="subjective" class="pd-textarea" placeholder="Chief complaint, history of present illness..."></textarea>
                        </div>
                        <div>
                            <label class="pd-label">Objective</label>
                            <textarea name="objective" class="pd-textarea" placeholder="Vital signs, physical exam findings..."></textarea>
                        </div>
                        <div>
                            <label class="pd-label">Assessment</label>
                            <textarea name="assessment" class="pd-textarea" placeholder="Differential diagnosis, clinical reasoning..."></textarea>
                        </div>
                        <div>
                            <label class="pd-label">Plan</label>
                            <textarea name="plan" class="pd-textarea" placeholder="Management, medications, follow-up..."></textarea>
                        </div>
                    </div>

                    <hr style="border: 0; border-top: 1px solid #e2edf1; margin: 20px 0;">

                    <div class="soap-grid">
                        <div>
                            <label class="pd-label">Final Diagnosis</label>
                            <textarea name="diagnosis" class="pd-textarea" placeholder="ICD-10 or clinical diagnosis..."></textarea>
                        </div>
                        <div>
                            <label class="pd-label">Digital Prescription</label>
                            <textarea name="prescription" class="pd-textarea" placeholder="Medication, Dosage, Frequency, Duration..."></textarea>
                        </div>
                    </div>

                    <div class="soap-full">
                        <label class="pd-label" style="color: #018a93;">Digital Signature Authorization</label>
                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <input type="text" name="signature_data" class="pd-input" style="flex: 1; min-width: 220px;" placeholder="Type full name to sign electronically">
                            <button type="button" class="session-btn primary" onclick="finalizeConsultation()">Finalize & Sign</button>
                        </div>
                        <p class="text-xs text-muted" style="margin-top: 8px;">By signing, you authorize this record and prescription as legally binding.</p>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT: Patient Profile & Workflow -->
    <div class="session-side">

        <!-- SESSION MESSAGES -->
        <div class="session-card">
            <div class="session-card-header">
                <div class="session-card-title"><?= icon('message') ?> Messages</div>
                <a href="messages.php" class="session-btn" style="height:32px;text-decoration:none;">Open Inbox</a>
            </div>
            <div id="sessionChatAlert" class="session-chat-alert"></div>
            <div class="session-chat-body" id="sessionChatBody">
                <div class="session-chat-empty" id="sessionChatEmpty">No messages yet. Start the conversation from here or from Messages.</div>
            </div>
            <div class="session-chat-composer">
                <input type="text" id="sessionMessageInput" placeholder="Message patient..." aria-label="Message patient">
                <button type="button" class="session-btn primary" id="sessionSendBtn" style="height:38px;">Send</button>
            </div>
        </div>

        <!-- EXTENSION CONTROL -->
        <div class="session-card">
            <div class="session-card-header"><div class="session-card-title"><?= icon('clock') ?> Session Management</div></div>
            <div class="session-card-body">
                <p class="text-xs text-muted mb-sm">Scheduled end time: <strong id="scheduledEndLabel"><?= htmlspecialchars($slot_end_label) ?></strong></p>
                <button class="session-btn primary" style="width: 100%;" id="extendSessionBtn" onclick="requestExtension()">
                    Extend Session (+15 min)
                </button>
                <p id="extensionMsg" class="text-xs" style="margin-top: 8px; display: none;"></p>
            </div>
        </div>

        <!-- PATIENT SNAPSHOT -->
        <div class="session-card">
            <div class="session-card-header"><div class="session-card-title"><?= icon('user') ?> Patient Snapshot</div></div>
            <div class="session-card-body">
                <div class="patient-head">
                    <div class="patient-avatar"><?= htmlspecialchars($patient['initials']) ?></div>
                    <div>
                        <div class="patient-name"><?= htmlspecialchars($patient['name']) ?></div>
                        <div class="patient-sub"><?= htmlspecialchars($patient['age']) ?>y &bull; <?= htmlspecialchars($patient['sex']) ?></div>
                    </div>
                </div>
                <div class="info-row"><span class="info-key">Blood type</span><span class="info-val"><?= htmlspecialchars($patient['blood_type']) ?></span></div>
                <div class="info-row"><span class="info-key">Medical Hx</span><span class="info-val"><?= htmlspecialchars($patient['history']) ?></span></div>
                <div class="info-row"><span class="info-key">Allergies</span><span class="info-val" style="color: #dc2626;"><?= htmlspecialchars($patient['allergies']) ?></span></div>
                <div class="info-row"><span class="info-key">Medications</span><span class="info-val"><?= htmlspecialchars($patient['medications']) ?></span></div>
                <div class="info-row"><span class="info-key">Triage</span><span class="info-val"><?= htmlspecialchars($patient['triage_level']) ?></span></div>
                <div class="complaint-box">
                    <strong>Chief Complaint</strong>
                    <?= htmlspecialchars($patient['complaint']) ?>
                </div>
            </div>
        </div>

        <!-- WORKFLOW ACTIONS -->
        <div class="session-card">
            <div class="session-card-header"><div class="session-card-title"><?= icon('arrow') ?> Referral & Follow-up</div></div>
            <div class="session-card-body">
                <div class="side-stack">
                    <select id="referralType" class="pd-input" style="width: 100%;">
                        <option value="">-- Issue Referral --</option>
                        <option value="ABTC">ABTC Program</option>
                        <option value="TB-DOTS">TB-DOTS Program</option>
                        <option value="LAB">Laboratory Referral</option>
                        <option value="SPEC">Specialist Referral</option>
                    </select>
                    <button class="session-btn primary" style="width: 100%;" onclick="issueReferral()">Generate Referral</button>
                    
                    <hr style="border: 0; border-top: 1px solid #e2edf1; margin: 10px 0;">
                    
                    <label class="pd-label">Schedule Follow-up</label>
                    <input type="date" id="followUpDate" class="pd-input" style="width: 100%;">
                    <button class="session-btn" style="width: 100%;" onclick="scheduleFollowUp()">Book Follow-up</button>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="<?= ASSET_BASE ?>/assets/js/messages-delete.js?v=3"></script>
<script>
// SESSION TIMER
let seconds = 0;
let timerActive = false;
const sessionMessages = <?= json_encode($session_messages, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const sessionConsultationId = <?= (int)$consultation_id ?>;
const sessionPatientId = <?= (int)$c['patient_id'] ?>;
const sessionCurrentUserId = <?= (int)$_SESSION['user_id'] ?>;
const sessionCsrf = <?= json_encode((string) ($_SESSION['csrf_token'] ?? '')) ?>;
const sessionProviderInitials = <?= json_encode($provider['initials'] ?? 'DR') ?>;
const sessionPatientInitials = <?= json_encode($patient['initials']) ?>;
const sessionAssetBase = <?= json_encode(ASSET_BASE) ?>;
const sessionSpokenMuteIds = new Set();
const sessionRecentMuteTexts = new Map();
let sessionChatRefreshTimer = null;
let sessionChatRefreshInFlight = false;
let sessionLastEventId = 0;
let sessionRealtimePoller = null;
let latestAiSummary = '';

function speakMuteTtsMessage(message, force) {
    if (!message || message.message_kind !== 'mute_tts' || message.is_deleted_for_everyone) return;
    const id = String(message.id || '');
    const key = String(message.message || '').trim().toLowerCase();
    if (!force) {
        if (id && sessionSpokenMuteIds.has(id)) return;
        const recentAt = sessionRecentMuteTexts.get(key);
        if (recentAt && (Date.now() - recentAt) < 15000) return;
    }
    if (id) sessionSpokenMuteIds.add(id);
    if (key) sessionRecentMuteTexts.set(key, Date.now());
    if (!('speechSynthesis' in window)) return;
    try {
        window.speechSynthesis.cancel();
        const utter = new SpeechSynthesisUtterance(String(message.message || ''));
        utter.lang = 'en-PH';
        window.speechSynthesis.speak(utter);
    } catch (e) { /* ignore */ }
}

function escapeChatHtml(value) {
    return MedConnectMessages.escapeHtml(value);
}

function showSessionChatAlert(message, type = 'success') {
    const alert = document.getElementById('sessionChatAlert');
    alert.textContent = message;
    alert.className = 'session-chat-alert show ' + type;
}

function clearSessionChatAlert() {
    const alert = document.getElementById('sessionChatAlert');
    alert.textContent = '';
    alert.className = 'session-chat-alert';
}

function renderSessionChat() {
    const body = document.getElementById('sessionChatBody');
    const empty = document.getElementById('sessionChatEmpty');
    body.querySelectorAll('.chat-row').forEach((node) => node.remove());
    empty.style.display = sessionMessages.length ? 'none' : 'flex';

    const fragment = document.createDocumentFragment();
    sessionMessages.forEach((message) => {
        const mine = Number(message.sender_id) === sessionCurrentUserId;
        const row = document.createElement('div');
        row.className = 'chat-row' + (mine ? ' mine' : '');
        const playBtn = (!mine && message.message_kind === 'mute_tts' && !message.is_deleted_for_everyone)
            ? `<button type="button" class="chat-mute-tts-play" data-play-mute-tts="${Number(message.id)}">▶ Play Audio</button>`
            : '';
        row.innerHTML = `
            <div class="chat-avatar">${escapeChatHtml(mine ? sessionProviderInitials : sessionPatientInitials)}</div>
            <div>
                ${MedConnectMessages.buildChatBubbleHtml(message, mine ? 'mine' : 'patient')}
                ${playBtn}
                <div class="chat-time" style="${mine ? 'text-align:right' : ''}">${escapeChatHtml(message.time || '')}</div>
            </div>
        `;
        fragment.appendChild(row);
    });
    body.appendChild(fragment);
    body.querySelectorAll('[data-play-mute-tts]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = Number(btn.getAttribute('data-play-mute-tts'));
            const msg = sessionMessages.find((m) => Number(m.id) === id);
            if (msg) speakMuteTtsMessage(msg, true);
        });
    });
    MedConnectMessages.bindMessageInteractions(body, sessionMessages, {
        assetBase: sessionAssetBase,
        onDeleted(result, eventType) {
            if (eventType === 'deleted_for_me') {
                const idx = sessionMessages.findIndex((msg) => Number(msg.id) === Number(result.data.message_id));
                if (idx >= 0) sessionMessages.splice(idx, 1);
            } else if (result.data?.message) {
                const idx = sessionMessages.findIndex((msg) => Number(msg.id) === Number(result.data.message_id));
                if (idx >= 0) sessionMessages[idx] = result.data.message;
            }
            renderSessionChat();
            showSessionChatAlert(eventType === 'deleted_for_everyone' ? 'Message deleted for everyone.' : 'Message deleted for you.', 'success');
            setTimeout(clearSessionChatAlert, 1400);
        },
        onError(message) { showSessionChatAlert(message, 'error'); }
    });
    body.scrollTop = body.scrollHeight;
}

function startSessionRealtime() {
    if (sessionRealtimePoller) sessionRealtimePoller.stop();
    sessionLastEventId = 0;
    sessionRealtimePoller = MedConnectMessages.createRealtimePoller(
        () => sessionConsultationId,
        () => sessionLastEventId,
        (id) => { sessionLastEventId = id; },
        (events) => {
            let changed = false;
            events.forEach((event) => {
                const before = sessionMessages.length;
                const next = MedConnectMessages.applyLocalDeletion(sessionMessages, event, sessionCurrentUserId);
                sessionMessages.length = 0;
                sessionMessages.push(...next);
                if (sessionMessages.length !== before || event.event_type === 'deleted_for_everyone') changed = true;
            });
            if (changed) renderSessionChat();
        },
        { assetBase: sessionAssetBase }
    );
    sessionRealtimePoller.start(2000);
}

async function refreshSessionChat() {
    if (sessionChatRefreshInFlight) return;
    sessionChatRefreshInFlight = true;
    try {
        const response = await fetch(`<?= ASSET_BASE ?>/app/api/messages/list.php?consultation_id=${encodeURIComponent(sessionConsultationId)}&_=${Date.now()}`, { cache: 'no-store' });
        const data = await response.json();
        if (data.success) {
            const incoming = data.messages || [];
            incoming.forEach((msg) => {
                if (msg.message_kind === 'mute_tts' && Number(msg.sender_id) !== sessionCurrentUserId) {
                    speakMuteTtsMessage(msg, false);
                }
            });
            sessionMessages.length = 0;
            sessionMessages.push(...incoming);
            renderSessionChat();
        }
    } catch (e) {
        // Keep the consultation page quiet during transient polling failures.
    } finally {
        sessionChatRefreshInFlight = false;
    }
}

async function sendSessionMessage() {
    const input = document.getElementById('sessionMessageInput');
    const button = document.getElementById('sessionSendBtn');
    const message = input.value.trim();
    if (!message) {
        showSessionChatAlert('Type a message first.', 'error');
        return;
    }

    button.disabled = true;
    button.textContent = 'Sending...';
    try {
        const data = await MedConnectMessages.sendMessage(sessionConsultationId, message, {
            assetBase: sessionAssetBase,
            csrfToken: sessionCsrf,
        });
        if (!data.success) {
            showSessionChatAlert(data.message || 'Could not send message.', 'error');
            return;
        }
        sessionMessages.push(data.data);
        input.value = '';
        renderSessionChat();
        showSessionChatAlert('Message sent.', 'success');
        setTimeout(clearSessionChatAlert, 1400);
    } catch (e) {
        showSessionChatAlert('Could not reach the message service.', 'error');
    } finally {
        button.disabled = false;
        button.textContent = 'Send';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    renderSessionChat();
    startSessionRealtime();
    document.getElementById('sessionSendBtn').addEventListener('click', sendSessionMessage);
    document.getElementById('sessionMessageInput').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            sendSessionMessage();
        }
    });
    sessionChatRefreshTimer = setInterval(refreshSessionChat, 2000);
    document.getElementById('analyzeTranscriptBtn').addEventListener('click', analyzeTranscript);
    document.getElementById('copyAiToSoapBtn').addEventListener('click', copyAiSummaryToSoap);
});

function renderAiTriage(triage) {
    const el = document.getElementById('aiTriage');
    if (!el) return;
    if (!triage || !triage.level || triage.level === 'unknown') {
        el.className = 'ai-triage-pill ai-triage--unknown';
        el.textContent = 'Not assessed';
        return;
    }
    el.className = 'ai-triage-pill ai-triage--' + triage.level;
    el.textContent = (triage.label || triage.level) + (triage.score ? ' · score ' + triage.score : '');
}

function renderAiDiseases(predictions) {
    const el = document.getElementById('aiDiseases');
    if (!el) return;
    el.innerHTML = '';
    if (!predictions || !predictions.length) {
        el.innerHTML = '<span class="text-xs text-muted">No condition suggestions (add more symptoms).</span>';
        return;
    }
    predictions.forEach((item) => {
        const card = document.createElement('div');
        card.className = 'ai-disease-card';
        card.innerHTML = '<strong>' + item.disease + '</strong> <span>' + item.confidence + '%</span>';
        if (item.precautions && item.precautions.length) {
            const note = document.createElement('div');
            note.className = 'text-xs text-muted';
            note.style.marginTop = '4px';
            note.textContent = 'Precautions: ' + item.precautions.slice(0, 2).join('; ');
            card.appendChild(note);
        }
        el.appendChild(card);
    });
}

function applyAiAnalysis(data) {
    latestAiSummary = data.summary || '';
    renderAiChips('aiSymptoms', data.symptoms || []);
    renderAiChips('aiMedicines', data.medicines || [], 'med');
    renderAiChips('aiUrgent', data.urgent_flags || [], 'urgent');
    renderAiTriage(data.triage || null);
    renderAiDiseases(data.disease_predictions || []);
    document.getElementById('aiEnglishTranscript').textContent = data.english_transcript || 'No translation available.';
    document.getElementById('aiSummary').textContent = latestAiSummary || 'No suggestions generated.';
}

function setVideoShellLive(isLive) {
    const shell = document.getElementById('videoInterface');
    const floatingBtn = document.getElementById('floatingScrollAiBtn');
    if (!shell) return;
    shell.classList.toggle('is-live', !!isLive);
    if (floatingBtn) {
        floatingBtn.classList.toggle('show', !!isLive);
    }
}

function toggleVideoShellSize() {
    const shell = document.getElementById('videoInterface');
    const btn = document.getElementById('toggleVideoSizeBtn');
    if (!shell || !btn) return;
    const minimized = shell.classList.toggle('is-minimized');
    btn.textContent = minimized ? 'Expand video' : 'Minimize video';
    if (minimized) {
        scrollToAiPanel();
    }
}

function scrollToAiPanel() {
    const card = document.getElementById('aiAssistantCard');
    if (card) {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function renderAiChips(targetId, values, type = '') {
    const target = document.getElementById(targetId);
    target.innerHTML = '';
    if (!values || !values.length) {
        const empty = document.createElement('span');
        empty.className = 'text-xs text-muted';
        empty.textContent = type === 'med' ? 'No medicines detected.' : (type === 'urgent' ? 'None detected.' : 'No symptoms detected.');
        target.appendChild(empty);
        return;
    }
    values.forEach((value) => {
        const chip = document.createElement('span');
        chip.className = 'ai-chip ' + type;
        chip.textContent = value;
        target.appendChild(chip);
    });
}

async function analyzeTranscript() {
    const input = document.getElementById('aiTranscriptInput');
    const button = document.getElementById('analyzeTranscriptBtn');
    const transcript = input.value.trim();
    if (!transcript) {
        alert('Paste or type transcript text first.');
        return;
    }

    button.disabled = true;
    button.textContent = 'Analyzing...';
    try {
        const response = await fetch('<?= ASSET_BASE ?>/app/api/ai/analyze_transcript.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ consultation_id: <?= (int)$consultation_id ?>, transcript })
        });
        const result = await response.json();
        if (!result.success) {
            alert(result.message || 'Could not analyze transcript.');
            return;
        }
        applyAiAnalysis(result.data);
    } catch (error) {
        alert('Could not reach AI analysis service.');
    } finally {
        button.disabled = false;
        button.textContent = 'Analyze Transcript';
    }
}

function copyAiSummaryToSoap() {
    if (!latestAiSummary) {
        alert('Analyze a transcript first.');
        return;
    }
    const assessment = document.querySelector('textarea[name="assessment"]');
    const current = assessment.value.trim();
    assessment.value = current ? current + "\n\n" + latestAiSummary : latestAiSummary;
    assessment.focus();
}

setInterval(() => {
    if (!timerActive) return;
    seconds++;
    let hrs = Math.floor(seconds / 3600);
    let mins = Math.floor((seconds % 3600) / 60);
    let secs = seconds % 60;
    document.getElementById('sessionTimer').textContent = 
        `${hrs.toString().padStart(2,'0')}:${mins.toString().padStart(2,'0')}:${secs.toString().padStart(2,'0')}`;
}, 1000);

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || !event.data) {
        return;
    }

    if (event.data.type === 'medconnect:transcript-status') {
        const status = document.getElementById('aiLiveStatus');
        const statusText = document.getElementById('aiLiveStatusText');
        status.className = 'ai-live-status ' + (event.data.status || '');
        statusText.textContent = event.data.message || 'Live transcript status updated.';
        return;
    }

    if (event.data.type === 'medconnect:transcript-update') {
        const input = document.getElementById('aiTranscriptInput');
        const interim = document.getElementById('aiInterimTranscript');
        if (event.data.transcript) {
            input.value = event.data.transcript;
        }
        interim.textContent = event.data.interim ? 'Listening: ' + event.data.interim : '';
        return;
    }

    if (event.data.type === 'medconnect:ai-analysis' && event.data.data) {
        applyAiAnalysis(event.data.data);
        if (!event.data.data.summary) {
            document.getElementById('aiSummary').textContent = latestAiSummary || 'Live AI is listening.';
        }
        return;
    }

    if (event.data.type === 'medconnect:mute-tts' && event.data.message) {
        const msg = event.data.message;
        speakMuteTtsMessage(msg, false);
        if (msg.message && document.getElementById('aiTranscriptInput')) {
            const input = document.getElementById('aiTranscriptInput');
            const stamp = msg.time || new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            input.value = (input.value ? input.value + '\n' : '') + `[Typed Voice ${stamp}] ${msg.message}`;
        }
        refreshSessionChat();
        return;
    }

    if (event.data.type === 'medconnect:call-ended') {
        timerActive = false;
        const frame = document.getElementById('videoFrame');
        if (frame) frame.src = 'about:blank';

        document.getElementById('activeCallUI').style.display = 'none';
        document.getElementById('videoPlaceholder').style.display = 'flex';
        document.getElementById('callStatusIndicator').style.color = '#64748b';
        document.getElementById('callStatusIndicator').textContent = '● ENDED';
        setVideoShellLive(false);
        return;
    }

    if (event.data.type === 'medconnect:minimize-video') {
        const shell = document.getElementById('videoInterface');
        const btn = document.getElementById('toggleVideoSizeBtn');
        if (shell && !shell.classList.contains('is-minimized')) {
            shell.classList.add('is-minimized');
            if (btn) btn.textContent = 'Expand video';
        }
        scrollToAiPanel();
        return;
    }

    if (event.data.type === 'medconnect:session-extended') {
        const endLabel = document.getElementById('scheduledEndLabel');
        const msg = document.getElementById('extensionMsg');
        if (endLabel && event.data.new_end_label) {
            endLabel.textContent = event.data.new_end_label;
        }
        if (msg) {
            msg.style.display = 'block';
            msg.style.color = '#22c55e';
            msg.textContent = 'Session extended by ' + (event.data.extension_mins || 15) + ' minutes.';
        }
    }
});

async function startVideoCall() {
    try {
        console.log("Starting video call for consultation:", <?= $consultation_id ?>);
        const res = await fetch('<?= ASSET_BASE ?>/app/api/consultations/start_video.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                consultation_id: <?= $consultation_id ?>,
                csrf_token: document.body.dataset.csrf || ''
            })
        });
        
        if (!res.ok) {
            throw new Error('HTTP error! status: ' + res.status);
        }
        
        const data = await res.json();
        console.log("API Response:", data);
        
        if (data.success) {
            document.getElementById('videoPlaceholder').style.display = 'none';
            document.getElementById('activeCallUI').style.display = 'block';
            document.getElementById('videoFrame').src = data.url;
            document.getElementById('callStatusIndicator').style.color = '#ef4444';
            document.getElementById('callStatusIndicator').textContent = '● LIVE';
            setVideoShellLive(true);
            timerActive = true;
            showPatientJoinLink(data.url);
        } else {
            alert(data.message || 'Could not start video session.');
        }
    } catch (e) {
        console.error("Video Call Error:", e);
        alert('Network error: ' + e.message + '. Please check console for details.');
    }
}

function showPatientJoinLink(url) {
    const box = document.getElementById('patientJoinLinkBox');
    const input = document.getElementById('patientJoinLinkInput');
    const openTabBtn = document.getElementById('openProviderVideoTabBtn');
    if (!box || !input || !url) return;
    input.value = /^https?:\/\//i.test(url) ? url : (window.location.origin + url);
    box.classList.add('is-visible');
    if (openTabBtn) {
        openTabBtn.style.display = 'inline-flex';
        openTabBtn.dataset.videoUrl = input.value;
    }
}

function openProviderVideoTab() {
    const btn = document.getElementById('openProviderVideoTabBtn');
    const frame = document.getElementById('videoFrame');
    const url = (btn && btn.dataset.videoUrl) || (frame && frame.src) || '';
    if (url) {
        window.open(url, '_blank', 'noopener');
    }
}

async function copyPatientJoinLink() {
    const input = document.getElementById('patientJoinLinkInput');
    if (!input || !input.value) return;
    try {
        await navigator.clipboard.writeText(input.value);
        alert('Patient join link copied. Paste it in an Incognito tab logged in as the patient.');
    } catch (e) {
        input.select();
        document.execCommand('copy');
        alert('Patient join link copied. Paste it in an Incognito tab logged in as the patient.');
    }
}

// Check if there's already an active session on load
window.addEventListener('load', () => {
    const existingToken = '<?= $room_token ?>';
    if (existingToken) {
        document.getElementById('videoPlaceholder').style.display = 'none';
        document.getElementById('activeCallUI').style.display = 'block';
        const joinUrl = '<?= ASSET_BASE ?>/views/consultation/video_room.php?token=' + existingToken;
        document.getElementById('videoFrame').src = joinUrl;
        document.getElementById('callStatusIndicator').style.color = '#ef4444';
        document.getElementById('callStatusIndicator').textContent = '● LIVE';
        setVideoShellLive(true);
        timerActive = true;
        showPatientJoinLink(joinUrl);
    }
});

// EXTEND SESSION
async function requestExtension() {
    const msg = document.getElementById('extensionMsg');
    const btn = document.getElementById('extendSessionBtn');
    const frame = document.getElementById('videoFrame');

    msg.style.display = 'block';
    msg.textContent = 'Checking schedule and applying extension...';
    msg.style.color = 'var(--text-muted)';
    if (btn) btn.disabled = true;

    try {
        const res = await fetch('<?= ASSET_BASE ?>/app/api/provider/check_extension.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                consultation_id: <?= $consultation_id ?>,
                extension_mins: 15,
                csrf_token: document.body.dataset.csrf || ''
            })
        });
        const data = await res.json();
        msg.textContent = data.message;
        msg.style.color = data.success ? '#22c55e' : '#ef4444';

        if (data.success) {
            const endLabel = document.getElementById('scheduledEndLabel');
            if (endLabel && data.new_end_label) {
                endLabel.textContent = data.new_end_label;
            }
            if (frame && frame.contentWindow) {
                frame.contentWindow.postMessage({
                    type: 'medconnect:extend-session',
                    extension_mins: data.extension_mins || 15,
                    new_end_label: data.new_end_label || '',
                    seconds_remaining: data.seconds_remaining || 0
                }, window.location.origin);
            }
        }
    } catch (e) {
        msg.textContent = 'Error extending session.';
        msg.style.color = '#ef4444';
    } finally {
        if (btn) btn.disabled = false;
    }
}

// SAVE SOAP NOTES (draft by default; finalize=true completes the visit)
async function saveSOAP(finalize = false) {
    const fd = new FormData(document.getElementById('soapForm'));
    fd.append('csrf_token', sessionCsrf || document.body.dataset.csrf || '');
    if (finalize) {
        fd.append('finalize', '1');
    }
    try {
        const res = await fetch('<?= ASSET_BASE ?>/app/api/provider/save_clinical_notes.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        });
        const data = await res.json();
        alert(data.message || (data.success ? 'Saved.' : 'Could not save notes.'));
        return data;
    } catch (e) {
        alert('Error saving notes.');
        return { success: false };
    }
}

// FINALIZE CONSULTATION
async function finalizeConsultation() {
    const sign = document.querySelector('input[name="signature_data"]').value;
    if (!sign || !String(sign).trim()) {
        return alert('Please provide your digital signature to finalize.');
    }
    if (!confirm('Finalize this consultation? This will close the session and save all records.')) {
        return;
    }
    const data = await saveSOAP(true);
    if (data && data.success) {
        window.location.href = '<?= ASSET_BASE ?>/views/provider/dashboard.php';
    }
}

async function issueReferral() {
    const type = document.getElementById('referralType').value;
    if (!type) return alert('Select referral type.');
    const reason = prompt('Referral reason / clinical notes:');
    if (!reason || !reason.trim()) return;
    try {
        const fd = new FormData();
        fd.append('patient_id', sessionPatientId);
        fd.append('consultation_id', sessionConsultationId);
        fd.append('referral_type', type);
        fd.append('reason', reason.trim());
        fd.append('csrf_token', sessionCsrf);
        const res = await fetch('<?= ASSET_BASE ?>/app/api/provider/create_referral.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        showSessionChatAlert(data.message || (data.success ? 'Referral created.' : 'Could not create referral.'), data.success ? 'success' : 'error');
    } catch (e) {
        showSessionChatAlert('Network error creating referral.', 'error');
    }
}

async function scheduleFollowUp() {
    const date = document.getElementById('followUpDate').value;
    if (!date) return alert('Select follow-up date.');
    try {
        const fd = new FormData();
        fd.append('patient_id', sessionPatientId);
        fd.append('consultation_id', sessionConsultationId);
        fd.append('followup_date', date);
        fd.append('message', 'Follow-up scheduled from consultation session.');
        fd.append('csrf_token', sessionCsrf);
        const res = await fetch('<?= ASSET_BASE ?>/app/api/provider/schedule_followup.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        showSessionChatAlert(data.message || (data.success ? 'Follow-up scheduled.' : 'Could not schedule follow-up.'), data.success ? 'success' : 'error');
    } catch (e) {
        showSessionChatAlert('Network error scheduling follow-up.', 'error');
    }
}
</script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
