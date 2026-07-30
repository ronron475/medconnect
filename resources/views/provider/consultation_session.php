<?php
session_start();
$active_page = 'consultation';
$page_title  = 'Tele-Consultation Session';
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require_once BASE_PATH . '/app/includes/message_deletion.php';
require_once BASE_PATH . '/app/includes/patient_health_summary.php';
require_once BASE_PATH . '/app/includes/provider_clinical_support.php';
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
$health_summary = patient_health_summary_load($pdo, (int) $c['patient_id']);

$patient = [
    'name' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
    'initials' => strtoupper(substr($c['first_name'] ?? 'P', 0, 1) . substr($c['last_name'] ?? '', 0, 1)),
    'age' => $c['age'] ?? '—',
    'sex' => $profile['sex'],
    'blood_type' => $profile['blood_type'],
    'history' => $profile['history'],
    'allergies' => $profile['allergies'],
    'medications' => $profile['medications'],
    'contact' => trim((string) ($profile['contact'] ?? '')),
    'address' => trim((string) ($profile['address'] ?? '')),
    'patient_number' => (string) ($health_summary['patient_number'] ?? ('MC-' . str_pad((string) $c['patient_id'], 6, '0', STR_PAD_LEFT))),
    'triage_level' => 'N/A',
    'complaint' => $c['consult_type'] ?: 'General consultation',
];
$patient_contact = (string) ($patient['contact'] ?? '');
$patient_email = '';
try {
    $em = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $em->execute([(int) $c['patient_id']]);
    $patient_email = trim((string) ($em->fetchColumn() ?: ''));
} catch (Throwable $e) {
    $patient_email = '';
}
$gmail_ready = defined('MAIL_USERNAME') && MAIL_USERNAME !== '' && defined('MAIL_PASSWORD') && MAIL_PASSWORD !== '';
if (!$gmail_ready) {
    require_once BASE_PATH . '/app/includes/mailer.php';
    $gmail_ready = defined('MAIL_USERNAME') && MAIL_USERNAME !== '' && defined('MAIL_PASSWORD') && MAIL_PASSWORD !== '';
}

$clinical_support = provider_consultation_clinical_support(
    $pdo,
    $consultation_id,
    (int) $c['patient_id']
);
$clinical_support_audit = provider_clinical_support_audit_trail($pdo, $consultation_id);
if ($clinical_support['available']) {
    $patient['triage_level'] = $clinical_support['risk_level'] !== ''
        ? $clinical_support['risk_level']
        : 'N/A';
    if ($clinical_support['chief_complaint'] !== '') {
        $patient['complaint'] = $clinical_support['chief_complaint'];
    }
}
$csp_original_complaint = (string) ($clinical_support['patient_original_complaint'] ?? '');
$csp_original_english = (string) ($clinical_support['patient_original_english'] ?? '');
if ($csp_original_complaint === '') {
    $csp_original_complaint = (string) ($patient['complaint'] ?? '');
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
.video-shell.is-floating {
    position: fixed;
    width: min(380px, calc(100vw - 24px));
    height: 240px;
    min-height: 0;
    aspect-ratio: auto;
    z-index: 2000;
    border-radius: 14px;
    box-shadow: 0 22px 50px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(148, 163, 184, 0.2);
    touch-action: none;
}
.video-shell.is-floating .video-placeholder {
    display: none !important;
}
.video-shell.is-floating .session-status {
    top: 8px;
    left: 8px;
    font-size: 11px;
    padding: 6px 10px;
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

/* Clinical Support Panel (video consultation) */
.csp-card .session-card-header {
    background: #f1f5f9;
}
.csp-eyebrow {
    margin: 0 0 4px;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #475569;
}
.csp-disclaimer {
    margin: 0 0 14px;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-left: 3px solid #334155;
    border-radius: 6px;
    background: #f8fafc;
    color: #334155;
    font-size: 12px;
    line-height: 1.45;
}
.csp-risk {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #fff;
}
.csp-risk__label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}
.csp-risk__value {
    font-size: 13px;
    font-weight: 800;
    color: #0f172a;
}
.csp-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    border: 1px solid transparent;
}
.csp-badge--unknown { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }
.csp-badge--routine,
.csp-badge--non_urgent { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
.csp-badge--urgent { background: #ffedd5; color: #c2410c; border-color: #fdba74; }
.csp-badge--emergency { background: #fee2e2; color: #b91c1c; border-color: #fca5a5; }
.csp-section {
    margin-bottom: 14px;
}
.csp-section:last-child { margin-bottom: 0; }
.csp-section__title {
    margin: 0 0 6px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #475569;
}
.csp-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.csp-chip {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 4px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #1e293b;
    font-size: 12px;
    font-weight: 600;
}
.csp-chip--warn {
    border-color: #fca5a5;
    background: #fef2f2;
    color: #991b1b;
}
.csp-list {
    margin: 0;
    padding-left: 18px;
    color: #334155;
    font-size: 13px;
    line-height: 1.5;
}
.csp-list li { margin-bottom: 4px; }
.csp-empty {
    margin: 0;
    color: #94a3b8;
    font-size: 12px;
    font-style: italic;
}
.csp-meta {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid #e2e8f0;
    font-size: 11px;
    color: #64748b;
}
.csp-warn-block {
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #fecaca;
    background: #fef2f2;
}
.csp-warn-block .csp-section__title {
    color: #991b1b;
}
.csp-override {
    margin-bottom: 14px;
    padding-bottom: 14px;
    border-bottom: 1px solid #e2e8f0;
}
.csp-complaint-input {
    width: 100%;
    min-height: 72px;
    margin-top: 4px;
    resize: vertical;
}
.csp-override__actions {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
    flex-wrap: wrap;
}
.csp-status {
    font-size: 12px;
    color: #64748b;
}
.csp-status.is-error { color: #b91c1c; }
.csp-status.is-ok { color: #047857; }
.csp-final-urgency {
    font-size: 1.05rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.01em;
}
.csp-compare {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 14px;
}
.csp-compare__card {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    padding: 10px 12px;
}
.csp-compare__card--doctor {
    border-color: #94a3b8;
    background: #f8fafc;
}
.csp-compare__label {
    display: block;
    margin-bottom: 6px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #64748b;
}
.csp-compare__text {
    margin: 0;
    font-size: 13px;
    color: #0f172a;
    line-height: 1.45;
    white-space: pre-wrap;
    word-break: break-word;
}
.csp-compare__eng {
    margin: 6px 0 0;
    font-size: 12px;
    color: #64748b;
}
.csp-manual {
    margin: 12px 0 14px;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
}
.csp-manual__row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 8px;
}
.csp-manual select,
.csp-manual textarea {
    width: 100%;
}
.csp-tools {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 12px 0 4px;
}
.csp-audit {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid #e2e8f0;
}
.csp-audit__list {
    list-style: none;
    margin: 8px 0 0;
    padding: 0;
    display: grid;
    gap: 8px;
    max-height: 220px;
    overflow-y: auto;
}
.csp-audit__item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    padding: 8px 10px;
}
.csp-audit__head {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
    color: #0f172a;
}
.csp-audit__meta {
    margin-top: 4px;
    font-size: 11px;
    color: #64748b;
    line-height: 1.4;
}
@media (max-width: 760px) {
    .csp-compare {
        grid-template-columns: 1fr;
    }
}

/* Post-call follow-up modal */
.fu-modal {
    position: fixed;
    inset: 0;
    z-index: 3200;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(15, 23, 42, 0.55);
}
.fu-modal.is-open { display: flex; }
.fu-modal__dialog {
    width: min(480px, 100%);
    background: #fff;
    border-radius: 12px;
    border: 1px solid #dce8ed;
    box-shadow: 0 24px 60px rgba(1, 42, 74, 0.28);
    overflow: hidden;
}
.fu-modal__header {
    padding: 16px 18px;
    border-bottom: 1px solid #e2edf1;
    background: #f8fafc;
}
.fu-modal__eyebrow {
    margin: 0 0 4px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #475569;
}
.fu-modal__title {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
    color: #012a4a;
}
.fu-modal__body { padding: 18px; }
.fu-modal__footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
    padding: 14px 18px;
    border-top: 1px solid #e2edf1;
    background: #f8fafc;
}
.fu-field { margin-bottom: 12px; }
.fu-field label {
    display: block;
    margin-bottom: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.fu-contact {
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #f1f5f9;
    font-weight: 700;
    color: #0f172a;
}
.fu-hint {
    margin: 6px 0 0;
    font-size: 12px;
    color: #64748b;
    line-height: 1.4;
}
.fu-check {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 13px;
    color: #334155;
}
.fu-check input { margin-top: 2px; }
.fu-status {
    margin-top: 10px;
    font-size: 12px;
    color: #64748b;
}
.fu-status.is-ok { color: #047857; }
.fu-status.is-error { color: #b91c1c; }

/* Provider Health Summary card */
.hs-card .session-card-header { background: #f1f5f9; }
.hs-pending {
    margin: 0 0 12px;
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid #fcd34d;
    background: #fffbeb;
    color: #92400e;
    font-size: 12px;
    font-weight: 600;
}
.hs-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 12px;
}
.hs-block {
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
}
.hs-label {
    display: block;
    margin-bottom: 6px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}
.hs-value {
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
}
.hs-section { margin-bottom: 12px; }
.hs-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.hs-chip {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 4px;
    border: 1px solid #cbd5e1;
    background: #fff;
    font-size: 12px;
    font-weight: 600;
    color: #1e293b;
}
.hs-chip--alert {
    border-color: #fca5a5;
    background: #fef2f2;
    color: #991b1b;
}
.hs-chip--med {
    border-color: #a7f3d0;
    background: #ecfdf5;
    color: #047857;
}
.hs-empty {
    font-size: 12px;
    color: #94a3b8;
    font-style: italic;
}
.hs-meta {
    margin: 12px 0 0;
    padding-top: 10px;
    border-top: 1px solid #e2e8f0;
    font-size: 11px;
    color: #64748b;
    line-height: 1.4;
}
@media (max-width: 760px) {
    .hs-grid { grid-template-columns: 1fr; }
    .video-shell {
        min-height: 52vh;
        aspect-ratio: auto;
    }
    .video-shell.is-floating {
        width: min(100vw - 16px, 360px);
        height: 200px;
        left: 8px !important;
        right: 8px;
    }
    .session-page {
        gap: 14px;
    }
}
@media (max-width: 1180px) {
    .session-page {
        grid-template-columns: 1fr;
    }
    .session-side {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .csp-card {
        grid-column: 1 / -1;
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
                <button type="button" class="video-size-btn" id="scrollToAiBtn" onclick="scrollToClinicalSupport()">Clinical Support</button>
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

        </div>

<button type="button" class="scroll-ai-btn" id="floatingScrollAiBtn" onclick="scrollToClinicalSupport()">Clinical Support</button>

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

        <!-- CLINICAL SUPPORT PANEL -->
        <div class="session-card csp-card">
            <div class="session-card-header">
                <div>
                    <p class="csp-eyebrow">Decision support</p>
                    <div class="session-card-title"><?= icon('scan') ?> Clinical Support Panel</div>
                </div>
            </div>
            <div class="session-card-body">
                <p class="csp-disclaimer">
                    Enter or override the final chief complaint, then re-assess. AI updates risk, conditions, questions, and actions.
                    The final diagnosis and treatment are always made by the doctor.
                </p>

                <div class="csp-compare" id="cspCompare">
                    <div class="csp-compare__card">
                        <span class="csp-compare__label">Patient original complaint</span>
                        <p class="csp-compare__text" id="cspOriginalComplaint"><?= htmlspecialchars($csp_original_complaint !== '' ? $csp_original_complaint : '—') ?></p>
                        <p class="csp-compare__eng" id="cspOriginalEnglish" <?= $csp_original_english === '' || strcasecmp($csp_original_english, $csp_original_complaint) === 0 ? 'hidden' : '' ?>>
                            English: <span id="cspOriginalEnglishText"><?= htmlspecialchars($csp_original_english) ?></span>
                        </p>
                    </div>
                    <div class="csp-compare__card csp-compare__card--doctor">
                        <span class="csp-compare__label">Doctor-finalized complaint</span>
                        <p class="csp-compare__text" id="cspFinalizedComplaint"><?= htmlspecialchars(
                            ($clinical_support['chief_complaint'] !== '' ? $clinical_support['chief_complaint'] : ($patient['complaint'] ?? '')) ?: '—'
                        ) ?></p>
                    </div>
                </div>

                <div class="csp-override">
                    <label class="csp-section__title" for="cspChiefComplaint">Final chief complaint (doctor override)</label>
                    <textarea
                        id="cspChiefComplaint"
                        class="pd-textarea csp-complaint-input"
                        rows="3"
                        placeholder="Type the finalized chief complaint from this consultation…"
                    ><?= htmlspecialchars($clinical_support['chief_complaint'] !== '' ? $clinical_support['chief_complaint'] : $patient['complaint']) ?></textarea>
                    <div class="csp-override__actions">
                        <button type="button" class="session-btn primary" id="cspReassessBtn">Re-assess with AI</button>
                        <span id="cspReassessStatus" class="csp-status" aria-live="polite"></span>
                    </div>
                </div>

                <div id="cspResults">
                <?php if (!$clinical_support['available']): ?>
                    <p class="csp-empty" id="cspEmptyState">No assessment yet. Enter the final chief complaint and click Re-assess with AI.</p>
                    <div id="cspFilledState" hidden>
                <?php else: ?>
                    <p class="csp-empty" id="cspEmptyState" hidden>No assessment yet. Enter the final chief complaint and click Re-assess with AI.</p>
                    <div id="cspFilledState">
                <?php endif; ?>
                    <?php
                    $riskBucket = preg_replace('/[^a-z_]/', '', strtolower((string) ($clinical_support['risk_bucket'] ?? 'unknown'))) ?: 'unknown';
                    $finalUrgency = (string) ($clinical_support['final_urgency'] ?? '');
                    if ($finalUrgency === '') {
                        $finalUrgency = match ($riskBucket) {
                            'emergency' => 'Emergency',
                            'urgent' => 'Urgent',
                            'non_urgent', 'routine' => 'Non-Urgent',
                            default => (string) ($clinical_support['risk_level'] ?? 'Not assessed'),
                        };
                    }
                    $aiUrgency = (string) ($clinical_support['ai_urgency'] ?? $finalUrgency);
                    ?>
                    <div class="csp-risk">
                        <div>
                            <div class="csp-risk__label">AI-assessed risk level</div>
                            <div class="csp-risk__value" id="cspRiskLevel"><?= htmlspecialchars($aiUrgency ?: 'Not assessed') ?></div>
                        </div>
                        <span class="csp-badge csp-badge--<?= htmlspecialchars($riskBucket) ?>" id="cspRiskBadge">
                            <?= htmlspecialchars($finalUrgency) ?>
                        </span>
                    </div>

                    <div class="csp-section">
                        <h4 class="csp-section__title">Final urgency prediction</h4>
                        <div class="csp-final-urgency" id="cspFinalUrgency"><?= htmlspecialchars($finalUrgency) ?></div>
                        <p class="csp-meta" style="margin-top:6px;border:0;padding:0;" id="cspOverrideNote">
                            <?php if (!empty($clinical_support['manual_urgency'])): ?>
                                Doctor manual override<?= $clinical_support['manual_override_note'] !== '' ? ': ' . htmlspecialchars($clinical_support['manual_override_note']) : '' ?>
                            <?php elseif (!empty($clinical_support['doctor_override'])): ?>
                                Based on doctor-finalized chief complaint
                            <?php else: ?>
                                Based on pre-consult triage (override above to update)
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="csp-manual">
                        <h4 class="csp-section__title">Manual urgency override</h4>
                        <p class="csp-empty" style="font-style:normal;margin-bottom:8px;">Disagree with AI? Set the clinical urgency and record a reason.</p>
                        <label class="csp-section__title" for="cspManualUrgency">Doctor urgency</label>
                        <select id="cspManualUrgency" class="pd-input">
                            <option value="emergency" <?= $riskBucket === 'emergency' ? 'selected' : '' ?>>Emergency</option>
                            <option value="urgent" <?= $riskBucket === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                            <option value="non_urgent" <?= in_array($riskBucket, ['non_urgent', 'routine'], true) ? 'selected' : '' ?>>Non-Urgent</option>
                        </select>
                        <label class="csp-section__title" for="cspManualNote" style="margin-top:8px;display:block;">Clinical reason (required)</label>
                        <textarea id="cspManualNote" class="pd-textarea" rows="2" placeholder="Why are you overriding the AI urgency?"><?= htmlspecialchars((string) ($clinical_support['manual_override_note'] ?? '')) ?></textarea>
                        <div class="csp-manual__row">
                            <button type="button" class="session-btn" id="cspOverrideUrgencyBtn">Save urgency override</button>
                            <span id="cspOverrideStatus" class="csp-status" aria-live="polite"></span>
                        </div>
                    </div>

                    <div class="csp-warn-block" id="cspWarnBlock" <?= empty($clinical_support['emergency_warning_signs']) ? 'hidden' : '' ?>>
                        <h4 class="csp-section__title">Emergency warning signs</h4>
                        <ul class="csp-list" id="cspWarnings">
                            <?php foreach ($clinical_support['emergency_warning_signs'] as $sign): ?>
                                <li><?= htmlspecialchars($sign) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="csp-section">
                        <h4 class="csp-section__title">Patient symptoms</h4>
                        <div class="csp-chips" id="cspSymptoms">
                            <?php if (!empty($clinical_support['symptoms'])): ?>
                                <?php foreach ($clinical_support['symptoms'] as $symptom): ?>
                                    <span class="csp-chip"><?= htmlspecialchars($symptom) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="csp-empty">No structured symptoms recorded yet.</p>
                            <?php endif; ?>
                        </div>
                        <p id="cspComplaintLine" style="margin: 8px 0 0; font-size: 13px; color: #334155; line-height: 1.45;" <?= $clinical_support['chief_complaint'] === '' ? 'hidden' : '' ?>>
                            <strong>Complaint:</strong> <span id="cspComplaintText"><?= htmlspecialchars($clinical_support['chief_complaint']) ?></span>
                            <span id="cspEnglishWrap" <?= ($clinical_support['english_complaint'] === '' || strcasecmp($clinical_support['english_complaint'], $clinical_support['chief_complaint']) === 0) ? 'hidden' : '' ?>>
                                <br><span style="color:#64748b;">English: <span id="cspEnglishText"><?= htmlspecialchars($clinical_support['english_complaint']) ?></span></span>
                            </span>
                        </p>
                    </div>

                    <div class="csp-section">
                        <h4 class="csp-section__title">Possible conditions</h4>
                        <div class="csp-chips" id="cspConditions">
                            <?php if (!empty($clinical_support['possible_conditions'])): ?>
                                <?php foreach ($clinical_support['possible_conditions'] as $condition): ?>
                                    <span class="csp-chip"><?= htmlspecialchars($condition) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="csp-empty">No differential suggested — clinical assessment required.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="csp-section">
                        <h4 class="csp-section__title">Suggested questions</h4>
                        <ul class="csp-list" id="cspQuestions">
                            <?php if (!empty($clinical_support['suggested_questions'])): ?>
                                <?php foreach ($clinical_support['suggested_questions'] as $question): ?>
                                    <li><?= htmlspecialchars($question) ?></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="csp-empty" style="list-style:none;margin-left:-18px;">No clarifying prompts available.</li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div class="csp-section">
                        <h4 class="csp-section__title">Recommended actions</h4>
                        <ul class="csp-list" id="cspActions">
                            <?php if (!empty($clinical_support['recommended_actions'])): ?>
                                <?php foreach ($clinical_support['recommended_actions'] as $action): ?>
                                    <li><?= htmlspecialchars($action) ?></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="csp-empty" style="list-style:none;margin-left:-18px;">No AI care actions listed for this assessment.</li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div class="csp-tools">
                        <button type="button" class="session-btn primary" id="cspCopyAssessmentBtn">Copy to Assessment</button>
                        <button type="button" class="session-btn" id="cspCopyPlanBtn">Copy actions to Plan</button>
                        <button type="button" class="session-btn" id="cspCopySubjectiveBtn">Copy complaint to Subjective</button>
                    </div>
                    <p id="cspCopyStatus" class="csp-status" aria-live="polite"></p>

                    <div class="csp-meta" id="cspMeta">
                        <?php if (($clinical_support['confidence_display'] ?? '') !== ''): ?>
                            Model confidence: <span id="cspConfidence"><?= htmlspecialchars($clinical_support['confidence_display']) ?></span>
                            ·
                        <?php else: ?>
                            <span id="cspConfidenceWrap" hidden>Model confidence: <span id="cspConfidence"></span> · </span>
                        <?php endif; ?>
                        <span id="cspAssessedLabel"><?= htmlspecialchars($clinical_support['assessed_label'] !== '' ? ('Assessed ' . $clinical_support['assessed_label']) : 'Awaiting doctor re-assessment') ?></span>
                    </div>
                    </div>
                </div>

                <div class="csp-audit">
                    <h4 class="csp-section__title">Audit trail</h4>
                    <ul class="csp-audit__list" id="cspAuditList">
                        <?php if ($clinical_support_audit === []): ?>
                            <li class="csp-empty" id="cspAuditEmpty">No re-assessments or overrides recorded yet.</li>
                        <?php else: ?>
                            <?php foreach ($clinical_support_audit as $entry): ?>
                                <li class="csp-audit__item">
                                    <div class="csp-audit__head">
                                        <span><?= htmlspecialchars($entry['event_label']) ?></span>
                                        <span><?= htmlspecialchars($entry['created_label']) ?></span>
                                    </div>
                                    <div class="csp-audit__meta">
                                        <?= htmlspecialchars($entry['provider_name']) ?>
                                        · Urgency: <?= htmlspecialchars($entry['urgency_label'] !== '' ? $entry['urgency_label'] : '—') ?>
                                        <?php if ($entry['event_type'] === 'urgency_override' && $entry['ai_urgency'] !== ''): ?>
                                            (AI was <?= htmlspecialchars($entry['ai_urgency']) ?>)
                                        <?php endif; ?>
                                        <?php if ($entry['audit_note'] !== ''): ?>
                                            <br><?= htmlspecialchars($entry['audit_note']) ?>
                                        <?php endif; ?>
                                        <?php if ($entry['chief_complaint'] !== ''): ?>
                                            <br>Complaint: <?= htmlspecialchars(strlen($entry['chief_complaint']) > 120 ? substr($entry['chief_complaint'], 0, 117) . '…' : $entry['chief_complaint']) ?>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

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

        <!-- PATIENT HEALTH SUMMARY (doctor view) -->
        <div class="session-card hs-card">
            <div class="session-card-header">
                <div>
                    <p class="csp-eyebrow" style="margin:0 0 2px;">Permanent medical profile</p>
                    <div class="session-card-title"><?= icon('user') ?> Patient Health Summary</div>
                </div>
            </div>
            <div class="session-card-body">
                <div class="patient-head">
                    <div class="patient-avatar"><?= htmlspecialchars($patient['initials']) ?></div>
                    <div>
                        <div class="patient-name"><?= htmlspecialchars($patient['name']) ?></div>
                        <div class="patient-sub">
                            <?= htmlspecialchars((string) $patient['patient_number']) ?>
                            · <?= htmlspecialchars((string) $patient['age']) ?>y
                            · <?= htmlspecialchars((string) $patient['sex']) ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($health_summary['pending_request'])): ?>
                    <p class="hs-pending">Patient has a pending medical profile update request.</p>
                <?php endif; ?>

                <div class="hs-grid">
                    <div class="hs-block">
                        <span class="hs-label">Blood type</span>
                        <div class="hs-value"><?= htmlspecialchars((string) ($health_summary['blood_type'] ?? $patient['blood_type'] ?: 'Not recorded')) ?></div>
                    </div>
                    <div class="hs-block">
                        <span class="hs-label">Triage (this visit)</span>
                        <div class="hs-value"><?= htmlspecialchars($patient['triage_level']) ?></div>
                    </div>
                </div>

                <div class="hs-section">
                    <span class="hs-label">Allergies</span>
                    <div class="hs-chips">
                        <?php
                        $allergyChips = $health_summary['allergies'] ?? [];
                        if ($allergyChips === [] && trim((string) $patient['allergies']) !== '' && !preg_match('/^none/i', (string) $patient['allergies'])) {
                            $allergyChips = preg_split('/[,;]+/', (string) $patient['allergies']) ?: [];
                        }
                        ?>
                        <?php if ($allergyChips === []): ?>
                            <span class="hs-empty">None known</span>
                        <?php else: ?>
                            <?php foreach ($allergyChips as $chip): ?>
                                <?php $chip = trim((string) $chip); if ($chip === '') continue; ?>
                                <span class="hs-chip hs-chip--alert"><?= htmlspecialchars($chip) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="hs-section">
                    <span class="hs-label">Existing conditions</span>
                    <div class="hs-chips">
                        <?php
                        $conditionChips = $health_summary['conditions'] ?? [];
                        if ($conditionChips === [] && trim((string) $patient['history']) !== '' && !preg_match('/^none/i', (string) $patient['history'])) {
                            $conditionChips = preg_split('/[,;]+/', (string) $patient['history']) ?: [];
                        }
                        ?>
                        <?php if ($conditionChips === []): ?>
                            <span class="hs-empty">None recorded</span>
                        <?php else: ?>
                            <?php foreach ($conditionChips as $chip): ?>
                                <?php $chip = trim((string) $chip); if ($chip === '') continue; ?>
                                <span class="hs-chip"><?= htmlspecialchars($chip) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="hs-section">
                    <span class="hs-label">Current medications</span>
                    <div class="hs-chips">
                        <?php
                        $medChips = $health_summary['medications'] ?? [];
                        if ($medChips === [] && trim((string) $patient['medications']) !== '' && !preg_match('/^none/i', (string) $patient['medications'])) {
                            $medChips = preg_split('/[,;]+/', (string) $patient['medications']) ?: [];
                        }
                        ?>
                        <?php if ($medChips === []): ?>
                            <span class="hs-empty">None recorded</span>
                        <?php else: ?>
                            <?php foreach ($medChips as $chip): ?>
                                <?php $chip = trim((string) $chip); if ($chip === '') continue; ?>
                                <span class="hs-chip hs-chip--med"><?= htmlspecialchars($chip) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-row"><span class="info-key">Contact</span><span class="info-val"><?= htmlspecialchars($patient_contact !== '' ? $patient_contact : '—') ?></span></div>
                <div class="info-row"><span class="info-key">Email</span><span class="info-val"><?= htmlspecialchars($patient_email !== '' ? $patient_email : '—') ?></span></div>
                <?php if (!empty($patient['address'])): ?>
                <div class="info-row"><span class="info-key">Address</span><span class="info-val"><?= htmlspecialchars($patient['address']) ?></span></div>
                <?php endif; ?>

                <div class="complaint-box">
                    <strong>Chief Complaint (this visit)</strong>
                    <?= htmlspecialchars($patient['complaint']) ?>
                </div>

                <p class="hs-meta">
                    Last updated:
                    <?= htmlspecialchars((string) ($health_summary['metadata']['last_updated_at_label'] ?? 'Not available')) ?>
                    · <?= htmlspecialchars((string) ($health_summary['metadata']['last_updated_by'] ?? 'Registration')) ?>
                </p>
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
                    <p class="text-xs text-muted" style="margin:6px 0 0;">Registered mobile: <strong><?= htmlspecialchars($patient_contact !== '' ? $patient_contact : 'Not on file') ?></strong></p>
                    <button class="session-btn" style="width: 100%; margin-top:8px;" onclick="scheduleFollowUp()">Book Follow-up</button>
                    <button type="button" class="session-btn primary" style="width: 100%; margin-top:8px;" onclick="openFollowUpModal()">Open follow-up form</button>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Post-call follow-up modal -->
<div id="followUpModal" class="fu-modal" aria-hidden="true">
  <div class="fu-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="followUpModalTitle">
    <div class="fu-modal__header">
      <p class="fu-modal__eyebrow">After video consultation</p>
      <h2 id="followUpModalTitle" class="fu-modal__title">Schedule patient follow-up</h2>
    </div>
    <div class="fu-modal__body">
      <p class="fu-hint" style="margin-top:0;margin-bottom:14px;">
        The video call has ended. Schedule a follow-up and send a Gmail reminder to the patient.
      </p>
      <div class="fu-field">
        <label>Patient</label>
        <div class="fu-contact"><?= htmlspecialchars($patient['name']) ?></div>
      </div>
      <div class="fu-field">
        <label>Registered email (Gmail reminder)</label>
        <div class="fu-contact" id="fuEmailDisplay"><?= htmlspecialchars($patient_email !== '' ? $patient_email : 'Not on file') ?></div>
        <p class="fu-hint">Pulled from the patient account. Reminder is sent via MedConnect Gmail SMTP.</p>
      </div>
      <div class="fu-field">
        <label>Registered mobile (reference)</label>
        <div class="fu-contact" id="fuContactDisplay"><?= htmlspecialchars($patient_contact !== '' ? $patient_contact : 'Not on file') ?></div>
      </div>
      <div class="fu-field">
        <label for="fuFollowUpDate">Follow-up date</label>
        <input type="date" id="fuFollowUpDate" class="pd-input" style="width:100%;">
      </div>
      <div class="fu-field">
        <label for="fuFollowUpMessage">Message / notes</label>
        <textarea id="fuFollowUpMessage" class="pd-textarea" rows="3" placeholder="Follow-up instructions for the patient…"></textarea>
      </div>
      <label class="fu-check">
        <input type="checkbox" id="fuSendEmail" <?= $patient_email !== '' ? 'checked' : 'disabled' ?>>
        <span>
          Send Gmail follow-up reminder
          <?php if ($patient_email === ''): ?>
            <span class="fu-hint" style="display:block;">No patient email on file — in-app reminder only.</span>
          <?php elseif ($gmail_ready): ?>
            <span class="fu-hint" style="display:block;">Gmail SMTP is ready.</span>
          <?php else: ?>
            <span class="fu-hint" style="display:block;">Mailer not ready — check Gmail SMTP settings.</span>
          <?php endif; ?>
        </span>
      </label>
      <p id="fuModalStatus" class="fu-status" aria-live="polite"></p>
    </div>
    <div class="fu-modal__footer">
      <button type="button" class="session-btn" id="fuSkipBtn">Skip for now</button>
      <button type="button" class="session-btn primary" id="fuSaveBtn">Schedule follow-up</button>
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
const sessionPatientContact = <?= json_encode($patient_contact) ?>;
const sessionPatientEmail = <?= json_encode($patient_email) ?>;
const sessionPatientName = <?= json_encode($patient['name']) ?>;
const sessionGmailReady = <?= $gmail_ready ? 'true' : 'false' ?>;
const sessionSpokenMuteIds = new Set();
const sessionRecentMuteTexts = new Map();
let sessionChatRefreshTimer = null;
let sessionChatRefreshInFlight = false;
let sessionLastEventId = 0;
let sessionRealtimePoller = null;

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
    const reassessBtn = document.getElementById('cspReassessBtn');
    if (reassessBtn) {
        reassessBtn.addEventListener('click', reassessClinicalSupport);
    }
    const overrideBtn = document.getElementById('cspOverrideUrgencyBtn');
    if (overrideBtn) {
        overrideBtn.addEventListener('click', overrideClinicalUrgency);
    }
    const copyAssessmentBtn = document.getElementById('cspCopyAssessmentBtn');
    if (copyAssessmentBtn) {
        copyAssessmentBtn.addEventListener('click', function () { copyClinicalSupportToSoap('assessment'); });
    }
    const copyPlanBtn = document.getElementById('cspCopyPlanBtn');
    if (copyPlanBtn) {
        copyPlanBtn.addEventListener('click', function () { copyClinicalSupportToSoap('plan'); });
    }
    const copySubjectiveBtn = document.getElementById('cspCopySubjectiveBtn');
    if (copySubjectiveBtn) {
        copySubjectiveBtn.addEventListener('click', function () { copyClinicalSupportToSoap('subjective'); });
    }
    const skipBtn = document.getElementById('fuSkipBtn');
    const saveBtn = document.getElementById('fuSaveBtn');
    const modal = document.getElementById('followUpModal');
    if (skipBtn) skipBtn.addEventListener('click', closeFollowUpModal);
    if (saveBtn) saveBtn.addEventListener('click', saveFollowUpFromModal);
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeFollowUpModal();
        });
    }
    const params = new URLSearchParams(window.location.search);
    if (params.get('followup') === '1') {
        openFollowUpModal({ fromCallEnd: true });
        params.delete('followup');
        const next = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
        window.history.replaceState({}, '', next);
    }
});

let currentClinicalSupport = <?= json_encode($clinical_support, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function escapeHtml(value) {
    const d = document.createElement('div');
    d.textContent = value == null ? '' : String(value);
    return d.innerHTML;
}

function renderCspChips(el, values, emptyText) {
    if (!el) return;
    el.innerHTML = '';
    if (!values || !values.length) {
        el.innerHTML = '<p class="csp-empty">' + escapeHtml(emptyText || 'None') + '</p>';
        return;
    }
    values.forEach((value) => {
        const chip = document.createElement('span');
        chip.className = 'csp-chip';
        chip.textContent = value;
        el.appendChild(chip);
    });
}

function renderCspList(el, values, emptyText) {
    if (!el) return;
    el.innerHTML = '';
    if (!values || !values.length) {
        el.innerHTML = '<li class="csp-empty" style="list-style:none;margin-left:-18px;">' + escapeHtml(emptyText || 'None') + '</li>';
        return;
    }
    values.forEach((value) => {
        const li = document.createElement('li');
        li.textContent = value;
        el.appendChild(li);
    });
}

function renderClinicalAudit(audit) {
    const list = document.getElementById('cspAuditList');
    if (!list) return;
    list.innerHTML = '';
    if (!audit || !audit.length) {
        list.innerHTML = '<li class="csp-empty" id="cspAuditEmpty">No re-assessments or overrides recorded yet.</li>';
        return;
    }
    audit.forEach((entry) => {
        const li = document.createElement('li');
        li.className = 'csp-audit__item';
        let meta = escapeHtml(entry.provider_name || 'Provider')
            + ' · Urgency: ' + escapeHtml(entry.urgency_label || '—');
        if (entry.event_type === 'urgency_override' && entry.ai_urgency) {
            meta += ' (AI was ' + escapeHtml(entry.ai_urgency) + ')';
        }
        if (entry.audit_note) {
            meta += '<br>' + escapeHtml(entry.audit_note);
        }
        if (entry.chief_complaint) {
            const short = String(entry.chief_complaint).length > 120
                ? String(entry.chief_complaint).slice(0, 117) + '…'
                : entry.chief_complaint;
            meta += '<br>Complaint: ' + escapeHtml(short);
        }
        li.innerHTML =
            '<div class="csp-audit__head"><span>' + escapeHtml(entry.event_label || entry.event_type) +
            '</span><span>' + escapeHtml(entry.created_label || '') + '</span></div>' +
            '<div class="csp-audit__meta">' + meta + '</div>';
        list.appendChild(li);
    });
}

function applyClinicalSupport(support) {
    if (!support) return;
    currentClinicalSupport = support;
    const emptyState = document.getElementById('cspEmptyState');
    const filledState = document.getElementById('cspFilledState');
    if (emptyState) emptyState.hidden = true;
    if (filledState) filledState.hidden = false;

    const riskLevel = document.getElementById('cspRiskLevel');
    const riskBadge = document.getElementById('cspRiskBadge');
    const finalUrgencyEl = document.getElementById('cspFinalUrgency');
    const overrideNote = document.getElementById('cspOverrideNote');
    const bucket = String(support.risk_bucket || 'unknown').replace(/[^a-z_]/g, '') || 'unknown';
    const finalUrgency = support.final_urgency || support.risk_level || 'Not assessed';
    const aiUrgency = support.ai_urgency || finalUrgency;

    if (riskLevel) riskLevel.textContent = aiUrgency || 'Not assessed';
    if (riskBadge) {
        riskBadge.className = 'csp-badge csp-badge--' + bucket;
        riskBadge.textContent = finalUrgency;
    }
    if (finalUrgencyEl) finalUrgencyEl.textContent = finalUrgency;
    if (overrideNote) {
        if (support.manual_urgency) {
            overrideNote.textContent = 'Doctor manual override' + (support.manual_override_note ? ': ' + support.manual_override_note : '');
        } else if (support.doctor_override) {
            overrideNote.textContent = 'Based on doctor-finalized chief complaint';
        } else {
            overrideNote.textContent = 'Based on pre-consult triage (override above to update)';
        }
    }

    const finalizedEl = document.getElementById('cspFinalizedComplaint');
    if (finalizedEl) {
        finalizedEl.textContent = support.chief_complaint || '—';
    }
    const originalEl = document.getElementById('cspOriginalComplaint');
    if (originalEl && support.patient_original_complaint) {
        originalEl.textContent = support.patient_original_complaint;
    }
    const originalEngWrap = document.getElementById('cspOriginalEnglish');
    const originalEngText = document.getElementById('cspOriginalEnglishText');
    if (originalEngWrap && originalEngText) {
        const eng = support.patient_original_english || '';
        const orig = support.patient_original_complaint || '';
        const show = eng && eng.toLowerCase() !== String(orig).toLowerCase();
        originalEngWrap.hidden = !show;
        originalEngText.textContent = eng;
    }

    const manualSelect = document.getElementById('cspManualUrgency');
    if (manualSelect && ['emergency', 'urgent', 'non_urgent'].indexOf(bucket) >= 0) {
        manualSelect.value = bucket === 'routine' ? 'non_urgent' : bucket;
    }

    const warnBlock = document.getElementById('cspWarnBlock');
    const warnings = support.emergency_warning_signs || [];
    if (warnBlock) {
        warnBlock.hidden = warnings.length === 0;
        renderCspList(document.getElementById('cspWarnings'), warnings, '');
    }

    renderCspChips(document.getElementById('cspSymptoms'), support.symptoms || [], 'No structured symptoms recorded yet.');
    renderCspChips(document.getElementById('cspConditions'), support.possible_conditions || [], 'No differential suggested — clinical assessment required.');
    renderCspList(document.getElementById('cspQuestions'), support.suggested_questions || [], 'No clarifying prompts available.');
    renderCspList(document.getElementById('cspActions'), support.recommended_actions || [], 'No AI care actions listed for this assessment.');

    const complaintLine = document.getElementById('cspComplaintLine');
    const complaintText = document.getElementById('cspComplaintText');
    const englishWrap = document.getElementById('cspEnglishWrap');
    const englishText = document.getElementById('cspEnglishText');
    if (complaintLine && complaintText) {
        const complaint = support.chief_complaint || '';
        complaintLine.hidden = !complaint;
        complaintText.textContent = complaint;
        if (englishWrap && englishText) {
            const eng = support.english_complaint || '';
            const showEng = eng && eng.toLowerCase() !== complaint.toLowerCase();
            englishWrap.hidden = !showEng;
            englishText.textContent = eng;
        }
    }

    const confidence = document.getElementById('cspConfidence');
    const confidenceWrap = document.getElementById('cspConfidenceWrap');
    if (confidence) confidence.textContent = support.confidence_display || '';
    if (confidenceWrap) confidenceWrap.hidden = !(support.confidence_display || '');

    const assessedLabel = document.getElementById('cspAssessedLabel');
    if (assessedLabel) {
        assessedLabel.textContent = support.assessed_label
            ? ('Assessed ' + support.assessed_label)
            : 'Just re-assessed';
    }
}

function appendSoapField(name, block) {
    const field = document.querySelector('#soapForm textarea[name="' + name + '"]');
    if (!field) return false;
    const current = field.value.trim();
    field.value = current ? (current + '\n\n' + block) : block;
    field.focus();
    return true;
}

function copyClinicalSupportToSoap(target) {
    const support = currentClinicalSupport || {};
    const status = document.getElementById('cspCopyStatus');
    if (!support.available) {
        if (status) {
            status.className = 'csp-status is-error';
            status.textContent = 'Run AI re-assessment first.';
        }
        return;
    }

    const symptoms = (support.symptoms || []).join(', ') || '—';
    const conditions = (support.possible_conditions || []).join('; ') || '—';
    const actions = (support.recommended_actions || []);
    const urgency = support.final_urgency || support.risk_level || 'Not assessed';
    const complaint = support.chief_complaint || '';
    let ok = false;
    let label = '';

    if (target === 'subjective') {
        const block = [
            'Chief complaint (doctor-finalized): ' + (complaint || '—'),
            support.english_complaint ? ('English: ' + support.english_complaint) : '',
            'Symptoms: ' + symptoms
        ].filter(Boolean).join('\n');
        ok = appendSoapField('subjective', block);
        label = 'Subjective';
    } else if (target === 'plan') {
        const block = [
            'Recommended actions (AI decision support — verify clinically):',
            ...(actions.length ? actions.map((a, i) => (i + 1) + '. ' + a) : ['—']),
            'Urgency: ' + urgency
        ].join('\n');
        ok = appendSoapField('plan', block);
        label = 'Plan';
    } else {
        const block = [
            'Clinical support summary (AI — verify before finalizing):',
            'Urgency: ' + urgency,
            'Symptoms: ' + symptoms,
            'Possible conditions: ' + conditions,
            support.manual_urgency && support.manual_override_note
                ? ('Doctor urgency override note: ' + support.manual_override_note)
                : ''
        ].filter(Boolean).join('\n');
        ok = appendSoapField('assessment', block);
        label = 'Assessment';
    }

    if (status) {
        status.className = ok ? 'csp-status is-ok' : 'csp-status is-error';
        status.textContent = ok ? ('Copied to SOAP ' + label + '.') : 'Could not find SOAP field.';
    }
    if (ok) {
        const soap = document.getElementById('soapForm');
        if (soap) soap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

async function reassessClinicalSupport() {
    const input = document.getElementById('cspChiefComplaint');
    const button = document.getElementById('cspReassessBtn');
    const status = document.getElementById('cspReassessStatus');
    const complaint = input ? input.value.trim() : '';
    if (!complaint) {
        if (status) {
            status.className = 'csp-status is-error';
            status.textContent = 'Enter the final chief complaint first.';
        }
        return;
    }

    if (button) {
        button.disabled = true;
        button.textContent = 'Re-assessing…';
    }
    if (status) {
        status.className = 'csp-status';
        status.textContent = 'Running AI assessment…';
    }

    try {
        const response = await fetch(sessionAssetBase + '/app/api/provider/clinical_support_reassess.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                consultation_id: String(sessionConsultationId),
                chief_complaint: complaint,
                csrf_token: sessionCsrf
            })
        });
        const result = await response.json();
        if (!result.success) {
            if (status) {
                status.className = 'csp-status is-error';
                status.textContent = result.message || 'Re-assessment failed.';
            }
            return;
        }
        applyClinicalSupport(result.support || {});
        if (result.audit) renderClinicalAudit(result.audit);
        if (status) {
            status.className = 'csp-status is-ok';
            status.textContent = 'Updated — ' + ((result.support && result.support.final_urgency) || 'assessment ready');
        }
    } catch (e) {
        if (status) {
            status.className = 'csp-status is-error';
            status.textContent = 'Could not reach clinical support service.';
        }
    } finally {
        if (button) {
            button.disabled = false;
            button.textContent = 'Re-assess with AI';
        }
    }
}

async function overrideClinicalUrgency() {
    const select = document.getElementById('cspManualUrgency');
    const noteEl = document.getElementById('cspManualNote');
    const button = document.getElementById('cspOverrideUrgencyBtn');
    const status = document.getElementById('cspOverrideStatus');
    const urgency = select ? select.value : '';
    const note = noteEl ? noteEl.value.trim() : '';

    if (!note) {
        if (status) {
            status.className = 'csp-status is-error';
            status.textContent = 'Enter a clinical reason for the override.';
        }
        return;
    }

    if (button) {
        button.disabled = true;
        button.textContent = 'Saving…';
    }
    if (status) {
        status.className = 'csp-status';
        status.textContent = 'Saving override…';
    }

    try {
        const response = await fetch(sessionAssetBase + '/app/api/provider/clinical_support_override.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                consultation_id: String(sessionConsultationId),
                urgency_bucket: urgency,
                audit_note: note,
                csrf_token: sessionCsrf
            })
        });
        const result = await response.json();
        if (!result.success) {
            if (status) {
                status.className = 'csp-status is-error';
                status.textContent = result.message || 'Override failed.';
            }
            return;
        }
        applyClinicalSupport(result.support || {});
        if (result.audit) renderClinicalAudit(result.audit);
        if (status) {
            status.className = 'csp-status is-ok';
            status.textContent = 'Override saved — ' + ((result.support && result.support.final_urgency) || urgency);
        }
    } catch (e) {
        if (status) {
            status.className = 'csp-status is-error';
            status.textContent = 'Could not save urgency override.';
        }
    } finally {
        if (button) {
            button.disabled = false;
            button.textContent = 'Save urgency override';
        }
    }
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

    const willFloat = !shell.classList.contains('is-floating');
    shell.classList.remove('is-minimized');
    shell.classList.toggle('is-floating', willFloat);
    btn.textContent = willFloat ? 'Expand video' : 'Minimize video';

    if (willFloat) {
        initFloatingVideoShell(shell);
        scrollToClinicalSupport();
    } else {
        shell.style.top = '';
        shell.style.left = '';
        shell.style.right = '';
        shell.style.bottom = '';
    }
}

function initFloatingVideoShell(shell) {
    if (!shell || shell.dataset.floatInit) return;
    shell.dataset.floatInit = '1';

    const defaultTop = 16 + (window.visualViewport ? window.visualViewport.offsetTop : 0);
    shell.style.top = defaultTop + 'px';
    shell.style.left = '16px';

    let dragging = false;
    let sx = 0;
    let sy = 0;
    let ox = 0;
    let oy = 0;

    function pointerDown(e) {
        if (!shell.classList.contains('is-floating')) return;
        if (e.target.closest('button') || e.target.closest('a') || e.target.closest('iframe')) return;
        dragging = true;
        const rect = shell.getBoundingClientRect();
        ox = rect.left;
        oy = rect.top;
        sx = e.clientX;
        sy = e.clientY;
        try { shell.setPointerCapture(e.pointerId); } catch (err) {}
        e.preventDefault();
    }

    function pointerMove(e) {
        if (!dragging) return;
        const x = ox + (e.clientX - sx);
        const y = oy + (e.clientY - sy);
        const maxX = window.innerWidth - shell.offsetWidth - 8;
        const maxY = window.innerHeight - shell.offsetHeight - 8;
        shell.style.left = Math.max(8, Math.min(maxX, x)) + 'px';
        shell.style.top = Math.max(8, Math.min(maxY, y)) + 'px';
        shell.style.right = 'auto';
        shell.style.bottom = 'auto';
    }

    function pointerUp(e) {
        if (!dragging) return;
        dragging = false;
        try { shell.releasePointerCapture(e.pointerId); } catch (err) {}
    }

    shell.addEventListener('pointerdown', pointerDown);
    shell.addEventListener('pointermove', pointerMove);
    shell.addEventListener('pointerup', pointerUp);
    shell.addEventListener('pointercancel', pointerUp);
}

function maximizeVideoShell() {
    const shell = document.getElementById('videoInterface');
    const btn = document.getElementById('toggleVideoSizeBtn');
    if (!shell) return;
    shell.classList.remove('is-floating', 'is-minimized');
    shell.style.top = '';
    shell.style.left = '';
    shell.style.right = '';
    shell.style.bottom = '';
    if (btn) btn.textContent = 'Minimize video';
}

function scrollToClinicalSupport() {
    const card = document.querySelector('.csp-card');
    if (card) {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
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

    if (event.data.type === 'medconnect:mute-tts' && event.data.message) {
        const msg = event.data.message;
        speakMuteTtsMessage(msg, false);
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
        openFollowUpModal({ fromCallEnd: true });
        return;
    }

    if (event.data.type === 'medconnect:minimize-video') {
        const shell = document.getElementById('videoInterface');
        const btn = document.getElementById('toggleVideoSizeBtn');
        if (shell && !shell.classList.contains('is-floating')) {
            shell.classList.remove('is-minimized');
            shell.classList.add('is-floating');
            initFloatingVideoShell(shell);
            if (btn) btn.textContent = 'Expand video';
        }
        scrollToClinicalSupport();
        return;
    }

    if (event.data.type === 'medconnect:maximize-video') {
        maximizeVideoShell();
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
        const res = await fetch(sessionAssetBase + '/app/api/provider/schedule_followup.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        showSessionChatAlert(data.message || (data.success ? 'Follow-up scheduled.' : 'Could not schedule follow-up.'), data.success ? 'success' : 'error');
    } catch (e) {
        showSessionChatAlert('Network error scheduling follow-up.', 'error');
    }
}

function defaultFollowUpDate() {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
}

function openFollowUpModal(opts) {
    const modal = document.getElementById('followUpModal');
    if (!modal) return;
    const dateEl = document.getElementById('fuFollowUpDate');
    const msgEl = document.getElementById('fuFollowUpMessage');
    const status = document.getElementById('fuModalStatus');
    const sideDate = document.getElementById('followUpDate');
    if (dateEl && !dateEl.value) {
        dateEl.value = (sideDate && sideDate.value) ? sideDate.value : defaultFollowUpDate();
    }
    if (msgEl && !msgEl.value) {
        msgEl.value = 'Please return for follow-up as advised by your provider.';
    }
    if (status) {
        status.className = 'fu-status';
        status.textContent = opts && opts.fromCallEnd
            ? 'Video consultation ended. Please schedule a follow-up if needed.'
            : '';
    }
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeFollowUpModal() {
    const modal = document.getElementById('followUpModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}

async function saveFollowUpFromModal() {
    const dateEl = document.getElementById('fuFollowUpDate');
    const msgEl = document.getElementById('fuFollowUpMessage');
    const emailEl = document.getElementById('fuSendEmail');
    const status = document.getElementById('fuModalStatus');
    const saveBtn = document.getElementById('fuSaveBtn');
    const date = dateEl ? dateEl.value : '';
    if (!date) {
        if (status) {
            status.className = 'fu-status is-error';
            status.textContent = 'Select a follow-up date.';
        }
        return;
    }

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Scheduling…';
    }
    if (status) {
        status.className = 'fu-status';
        status.textContent = 'Saving follow-up…';
    }

    try {
        const fd = new FormData();
        fd.append('patient_id', sessionPatientId);
        fd.append('consultation_id', sessionConsultationId);
        fd.append('followup_date', date);
        fd.append('message', (msgEl && msgEl.value.trim()) ? msgEl.value.trim() : 'Follow-up scheduled after video consultation.');
        fd.append('csrf_token', sessionCsrf);
        fd.append('send_email', (emailEl && emailEl.checked) ? '1' : '0');
        const res = await fetch(sessionAssetBase + '/app/api/provider/schedule_followup.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });
        const data = await res.json();
        if (!data.success) {
            if (status) {
                status.className = 'fu-status is-error';
                status.textContent = data.message || 'Could not schedule follow-up.';
            }
            return;
        }
        const sideDate = document.getElementById('followUpDate');
        if (sideDate) sideDate.value = date;
        if (status) {
            status.className = 'fu-status is-ok';
            status.textContent = data.message || 'Follow-up scheduled.';
        }
        showSessionChatAlert(data.message || 'Follow-up scheduled.', 'success');
        setTimeout(closeFollowUpModal, 900);
    } catch (e) {
        if (status) {
            status.className = 'fu-status is-error';
            status.textContent = 'Network error scheduling follow-up.';
        }
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Schedule follow-up';
        }
    }
}

</script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
