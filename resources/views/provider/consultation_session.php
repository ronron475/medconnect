<?php
$active_page = 'consultation';
$page_title  = 'Tele-Consultation';
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require_once BASE_PATH . '/app/includes/message_deletion.php';
require_once BASE_PATH . '/app/includes/patient_health_summary.php';
require_once BASE_PATH . '/app/includes/provider_clinical_support.php';
require_once BASE_PATH . '/app/includes/clinical_tables.php';
require_once BASE_PATH . '/app/includes/patient_consultation_records.php';
require_once BASE_PATH . '/app/includes/clinical_note_signature.php';
require_once BASE_PATH . '/app/includes/consultation_video_history.php';
require_once BASE_PATH . '/app/includes/community_bhw_activity.php';
require __DIR__ . '/partials/queue_helpers.php';

clinical_tables_ensure($pdo);
patient_consultation_records_schema_ensure($pdo);
clinical_note_signature_schema_ensure($pdo);
$GLOBALS['pdo'] = $pdo;

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

$clinical_note = null;
try {
    $cnStmt = $pdo->prepare('SELECT * FROM clinical_notes WHERE consultation_id = ? AND provider_id = ? LIMIT 1');
    $cnStmt->execute([$consultation_id, (int) $_SESSION['user_id']]);
    $clinical_note = $cnStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    $clinical_note = null;
}

$soap_finalized = patient_consultation_is_finalized(
    (string) ($c['status'] ?? ''),
    $clinical_note['signature_data'] ?? '',
    $clinical_note['finalized_at'] ?? null
);
$consultation_completed = $soap_finalized;
$soap_readonly = $soap_finalized;
$soap_signer = clinical_note_provider_identity($pdo, (int) $_SESSION['user_id']);
$soap_signed_by = $clinical_note
    ? clinical_note_signed_by_label($clinical_note, $soap_signer['display_name'])
    : '';
$soap_signed_at = $clinical_note ? clinical_note_signed_at_label($clinical_note) : '';

$session_access = queue_session_access($c);
$history_view = in_array(strtolower(trim((string) ($c['status'] ?? ''))), ['completed', 'cancelled', 'ended', 'closed'], true);
if (!$session_access['allowed'] && !$history_view) {
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
$bhw_activity = community_bhw_activity_load($pdo, (int) $c['patient_id']);
$bhw_activity_variant = 'provider';

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
$video_doctor_name = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
if ($video_doctor_name === '') {
    $video_doctor_name = trim((string) ($c['provider_name'] ?? 'Healthcare Provider'));
}
if ($video_doctor_name !== '' && !preg_match('/^dr\.?\s+/i', $video_doctor_name)) {
    $video_doctor_name = 'Dr. ' . $video_doctor_name;
}
$video_history = consultation_video_history_summary(
    (string) ($c['status'] ?? ''),
    consultation_video_session_row($pdo, $consultation_id),
    isset($c['completed_at']) ? (string) $c['completed_at'] : null,
    $video_doctor_name,
    trim((string) ($c['first_name'] ?? '') . ' ' . (string) ($c['last_name'] ?? ''))
);
$show_video_demo_tip = function_exists('medconnect_is_local_dev_host') && medconnect_is_local_dev_host();
$localhost_app_url = 'http://localhost' . (ASSET_BASE !== '' ? ASSET_BASE : '');
?>

<?php $soapSigCssVer = (int) @filemtime(ASSETS_PATH . '/css/soap-signature.css'); ?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/soap-signature.css?v=<?= $soapSigCssVer ?: time() ?>"/>
<style>
.session-page {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(280px, 360px);
    gap: 22px;
    align-items: start;
    overflow-x: hidden;
    max-width: 100%;
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
    transition: height 0.25s ease, min-height 0.25s ease, aspect-ratio 0.25s ease, border-radius 0.2s ease;
}
.video-panel {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.video-pre-call {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 20px;
    background: linear-gradient(165deg, #0f172a 0%, #020617 100%);
    color: #f8fafc;
    text-align: center;
}
.video-pre-call__inner {
    width: 100%;
    max-width: 380px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}
.video-pre-call__icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.14);
    color: #5eead4;
}
.video-pre-call__title {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.02em;
}
.video-pre-call__patient {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: #cbd5e1;
}
.video-pre-call__prompt {
    margin: 0;
    font-size: 13px;
    color: #94a3b8;
}
.video-pre-call__start {
    margin-top: 8px;
    min-height: 48px;
    width: 100%;
    max-width: 280px;
}
.video-pre-call-help {
    padding: 14px 16px;
    border-radius: 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
    font-size: 12px;
    line-height: 1.55;
}
.video-pre-call-help ol {
    margin: 8px 0 0 18px;
    padding: 0;
}
.video-pre-call-help li { margin-bottom: 4px; }
.video-panel.is-call-active .video-pre-call-help {
    display: none;
}
.video-shell:not(.is-call-active) {
    aspect-ratio: auto;
    min-height: 0;
    height: auto;
    background: transparent;
    box-shadow: none;
    overflow: visible;
}
.video-shell:not(.is-call-active) .video-pre-call {
    position: relative;
    inset: auto;
    min-height: 220px;
    border-radius: 14px;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.14);
}
.video-shell:not(.is-call-active) .session-status,
.video-shell:not(.is-call-active) .video-shell-tools,
.video-shell:not(.is-call-active) .mobile-call-expand-btn {
    display: none !important;
}
.mc-provider-video-dock {
    width: 100%;
    height: 100%;
    min-height: 0;
}
.mc-provider-video-dock .mc-session-float-shell.is-docked {
    min-height: 0;
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
/* Active call: single iframe UI only — no duplicate LIVE overlay or shell chrome */
.video-shell.is-call-active {
    background: #000;
    min-height: 0;
    aspect-ratio: auto;
    overflow: hidden;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.2);
}
.video-shell.is-call-active .video-pre-call {
    display: none !important;
}
.video-shell.is-call-active .video-shell-tools,
.video-shell.is-call-active .session-status {
    display: none !important;
}
@media (min-width: 769px) {
    .session-page {
        align-items: stretch;
    }
    .video-shell.is-call-active {
        width: 100%;
        height: auto;
        min-height: clamp(240px, 32dvh, 320px);
        max-height: calc(100dvh - var(--mc-header-offset, 64px) - 2.5rem);
        aspect-ratio: 16 / 9;
    }
    .video-shell.is-call-active .video-shell-tools {
        display: none !important;
    }
}

/* Desktop in-app expand: keep the same iframe docked; only the page chrome changes. */
@media (min-width: 769px) {
    body.provider-body.consultation-desktop-video-expanded {
        overflow: hidden !important;
    }
    body.provider-body.consultation-desktop-video-expanded .sb-aqua,
    body.provider-body.consultation-desktop-video-expanded .sidebar {
        display: none !important;
    }
    body.provider-body.consultation-desktop-video-expanded .pd-header {
        left: 0 !important;
        width: 100% !important;
    }
    body.provider-body.consultation-desktop-video-expanded .main-content.provider-main {
        margin-left: 0 !important;
        width: 100% !important;
    }
    body.provider-body.consultation-desktop-video-expanded .provider-page-body {
        padding: var(--mc-header-offset, var(--provider-header-h, 64px)) 0 0 !important;
        height: 100dvh;
        max-height: 100dvh;
        overflow: hidden;
    }
    body.provider-body.consultation-desktop-video-expanded .session-page {
        display: block;
        gap: 0;
        margin: 0;
        padding: 0;
        height: calc(100dvh - var(--mc-header-offset, var(--provider-header-h, 64px)));
        max-height: calc(100dvh - var(--mc-header-offset, var(--provider-header-h, 64px)));
        overflow: hidden;
    }
    body.provider-body.consultation-desktop-video-expanded .session-side,
    body.provider-body.consultation-desktop-video-expanded .session-left > .session-card,
    body.provider-body.consultation-desktop-video-expanded #soapDocumentation,
    body.provider-body.consultation-desktop-video-expanded #videoConsultationSessionCard,
    body.provider-body.consultation-desktop-video-expanded .video-pre-call-help,
    body.provider-body.consultation-desktop-video-expanded .video-demo-link,
    body.provider-body.consultation-desktop-video-expanded .scroll-ai-btn,
    body.provider-body.consultation-desktop-video-expanded #floatingScrollAiBtn {
        display: none !important;
    }
    body.provider-body.consultation-desktop-video-expanded .session-left,
    body.provider-body.consultation-desktop-video-expanded .video-panel,
    body.provider-body.consultation-desktop-video-expanded .video-panel.is-call-active {
        display: flex;
        flex-direction: column;
        gap: 0;
        height: 100%;
        min-height: 0;
        max-height: 100%;
    }
    body.provider-body.consultation-desktop-video-expanded .video-shell.is-call-active {
        flex: 1 1 auto;
        position: relative;
        width: 100%;
        height: 100%;
        min-height: 0;
        max-height: none;
        border-radius: 0;
        box-shadow: none;
    }
    body.provider-body.consultation-desktop-video-expanded .mc-provider-video-dock,
    body.provider-body.consultation-desktop-video-expanded .mc-provider-video-dock .mc-session-float-shell.is-docked {
        height: 100%;
        min-height: 100%;
        max-height: none;
        border-radius: 0;
    }
}
.video-shell.is-call-active .active-call {
    position: absolute;
    inset: 0;
    display: block !important;
}
.video-shell.is-call-active .mc-provider-video-dock,
.video-shell.is-call-active .mc-provider-video-dock .mc-session-float-shell.is-docked {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    min-height: 0;
    max-height: none;
}
.mobile-call-expand-btn {
    display: none !important;
    position: absolute;
    right: 12px;
    bottom: 12px;
    z-index: 6;
    width: 48px;
    height: 48px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.22);
    background: rgba(15, 23, 42, 0.88);
    color: #fff;
    cursor: pointer;
    align-items: center;
    justify-content: center;
    -webkit-tap-highlight-color: transparent;
}
.mobile-call-expand-btn svg {
    width: 22px;
    height: 22px;
}
/* Mobile dedicated call layer — same iframe, presentation only.
   Keep the provider header usable. Stretch the video from the header
   to the bottom of the dynamic viewport so the white session canvas
   cannot show underneath a short 16:9 tile. */
body.consultation-mobile-call-fullscreen {
    overflow: hidden !important;
    background: #0b1220;
}
body.consultation-mobile-call-fullscreen .scroll-ai-btn,
body.consultation-mobile-call-fullscreen #floatingScrollAiBtn,
body.consultation-mobile-call-fullscreen .session-side,
body.consultation-mobile-call-fullscreen .session-left > .session-card,
body.consultation-mobile-call-fullscreen .sb-aqua,
body.consultation-mobile-call-fullscreen .sidebar,
body.consultation-mobile-call-fullscreen .portal-mobile-nav,
body.consultation-mobile-call-fullscreen .mc-messages-fab,
body.consultation-mobile-call-fullscreen .messages-fab,
body.consultation-mobile-call-fullscreen .video-pre-call-help,
body.consultation-mobile-call-fullscreen .video-demo-link {
    display: none !important;
}
body.consultation-mobile-call-fullscreen .pd-header {
    z-index: 500;
}
body.consultation-mobile-call-fullscreen .provider-page-body,
body.consultation-mobile-call-fullscreen .main-content.provider-main {
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
    height: 100dvh;
    max-height: 100dvh;
    min-height: 0;
    overflow: hidden;
    background: #0b1220;
}
body.consultation-mobile-call-fullscreen .session-page {
    display: block;
    padding: 0;
    margin: 0;
    gap: 0;
    height: 100%;
    max-height: 100%;
    min-height: 0;
    overflow: hidden;
    background: #0b1220;
}
body.consultation-mobile-call-fullscreen .session-left,
body.consultation-mobile-call-fullscreen .video-panel,
body.consultation-mobile-call-fullscreen .video-panel.is-call-active {
    display: flex;
    flex-direction: column;
    gap: 0;
    height: 100%;
    min-height: 0;
    max-height: 100%;
    background: #0b1220;
}
body.consultation-mobile-call-fullscreen .video-shell.is-call-active.is-mobile-fullscreen {
    position: fixed;
    top: var(--mc-header-offset, var(--provider-header-h, 64px));
    right: 0;
    left: 0;
    bottom: auto;
    width: 100%;
    height: calc(100vh - var(--mc-header-offset, var(--provider-header-h, 64px)));
    height: calc(100dvh - var(--mc-header-offset, var(--provider-header-h, 64px)));
    max-height: none;
    min-height: 0;
    aspect-ratio: unset;
    border-radius: 0;
    z-index: 400;
    padding: 0;
    box-sizing: border-box;
    overflow: hidden;
    box-shadow: none;
    background: #0b1220;
}
body.consultation-mobile-call-fullscreen .video-shell.is-call-active.is-mobile-fullscreen .mobile-call-expand-btn {
    display: none !important;
}
body.consultation-true-fullscreen {
    overflow: hidden !important;
    background: #0b1220;
}
body.consultation-true-fullscreen .pd-header,
body.consultation-true-fullscreen .pd-header-page,
body.consultation-true-fullscreen .portal-mobile-nav {
    display: none !important;
}
body.consultation-true-fullscreen .provider-page-body,
body.consultation-true-fullscreen .main-content.provider-main,
body.consultation-true-fullscreen .session-page,
body.consultation-true-fullscreen .session-left,
body.consultation-true-fullscreen .video-panel,
body.consultation-true-fullscreen .video-panel.is-call-active {
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
    height: 100% !important;
    max-height: none !important;
    min-height: 0 !important;
    overflow: hidden !important;
    background: #0b1220 !important;
}
body.consultation-true-fullscreen .video-shell.is-call-active,
body.consultation-true-fullscreen .video-shell.is-call-active.is-mobile-fullscreen {
    position: fixed !important;
    inset: 0 !important;
    top: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: var(--mc-true-fs-height, 100dvh) !important;
    max-height: none !important;
    min-height: 0 !important;
    aspect-ratio: unset !important;
    border-radius: 0 !important;
    z-index: 10050 !important;
    overflow: hidden !important;
    background: #0b1220 !important;
}
body.consultation-true-fullscreen .mc-provider-video-dock,
body.consultation-true-fullscreen .mc-provider-video-dock .mc-session-float-shell.is-docked,
body.consultation-true-fullscreen .mc-provider-video-dock .mc-session-float-body,
body.consultation-true-fullscreen .mc-provider-video-dock iframe,
body.consultation-true-fullscreen .video-shell.is-call-active iframe {
    position: absolute !important;
    inset: 0 !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 0 !important;
    max-height: none !important;
    border: 0 !important;
    border-radius: 0 !important;
}
body.consultation-mobile-call-fullscreen .mc-provider-video-dock,
body.consultation-mobile-call-fullscreen .mc-provider-video-dock .mc-session-float-shell.is-docked,
body.consultation-mobile-call-fullscreen .mc-provider-video-dock .mc-session-float-body,
body.consultation-mobile-call-fullscreen .mc-provider-video-dock iframe {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    min-height: 0;
    max-height: none;
    border: 0;
    border-radius: 0;
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
    align-items: flex-start;
    margin-bottom: 12px;
}
.chat-row.mine {
    flex-direction: row-reverse;
}
.chat-row.mine .chat-avatar {
    display: none;
}
.chat-msg-col {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.chat-row.mine .chat-msg-col {
    align-items: flex-end;
}
.chat-msg-head {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
}
.chat-row.mine .chat-msg-head {
    justify-content: flex-end;
}
.chat-msg-name {
    font-size: 11px;
    font-weight: 800;
    color: #334155;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 140px;
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
    word-break: break-word;
    overflow-wrap: anywhere;
}
.chat-bubble img,
.chat-bubble video,
.chat-bubble audio {
    max-width: 100%;
    height: auto;
}
.chat-bubble.patient {
    background: #fff;
    border: 1px solid #e2edf1;
    color: #334155;
    border-bottom-left-radius: 4px;
}
.chat-bubble.mine {
    background: #0f766e;
    border: 1px solid #0d9488;
    color: #fff;
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
    flex-shrink: 0;
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
.session-chat-composer .session-btn {
    height: 38px;
    flex-shrink: 0;
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
.csp-triage-stack {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 14px;
}
.csp-triage-row {
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #fff;
}
.csp-triage-row--ai { background: #f8fafc; }
.csp-triage-row--final {
    border-color: #0f766e;
    background: #f0fdfa;
}
.csp-triage-row--final .csp-final-urgency { color: #115e59; text-transform: uppercase; }
.csp-triage-row--doctor .csp-final-urgency { text-transform: uppercase; }
.csp-reason-text {
    margin: 4px 0 0;
    font-size: 13px;
    color: #0f172a;
    line-height: 1.45;
}
.csp-emergency-modal[hidden] { display: none !important; }
.csp-emergency-modal {
    position: fixed;
    inset: 0;
    z-index: 200200;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.csp-emergency-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.72);
}
.csp-emergency-modal__card {
    position: relative;
    z-index: 1;
    width: min(480px, 100%);
    max-height: min(92dvh, 720px);
    overflow: auto;
    background: #fff;
    border-radius: 16px;
    padding: 22px 20px 18px;
    box-shadow: 0 24px 60px rgba(0,0,0,0.28);
    border: 2px solid #b91c1c;
}
.csp-emergency-modal__eyebrow {
    margin: 0 0 6px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #b91c1c;
}
.csp-emergency-modal__title {
    margin: 0 0 14px;
    font-size: 22px;
    font-weight: 800;
    color: #7f1d1d;
}
.csp-emergency-modal__dl {
    display: grid;
    grid-template-columns: 140px 1fr;
    gap: 8px 10px;
    margin: 0 0 16px;
    font-size: 13px;
}
.csp-emergency-modal__lead {
    margin: 0 0 14px;
    font-size: 14px;
    line-height: 1.5;
    color: #334155;
    font-weight: 600;
}
.csp-emergency-modal__facility {
    margin: 0 0 14px;
    padding: 12px;
    border-radius: 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}
.csp-emergency-modal__facility[hidden] { display: none !important; }
.csp-emergency-modal__facility-heading {
    margin: 0 0 6px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #64748b;
}
.csp-emergency-modal__facility-name {
    display: block;
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
}
.csp-emergency-modal__facility-meta {
    margin: 4px 0 0;
    font-size: 13px;
    color: #475569;
}
.csp-emergency-modal__status {
    margin: 0 0 16px;
    padding: 10px 12px;
    border-radius: 8px;
    background: #fff7ed;
    color: #9a3412;
    font-size: 13px;
    font-weight: 700;
}
.csp-emergency-modal__status.is-live {
    background: #ecfdf5;
    color: #047857;
}
.csp-emergency-modal__actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.csp-emergency-modal__actions .session-btn { min-height: 46px; width: 100%; }
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
.fu-decision {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.fu-decision .session-btn {
    flex: 1 1 180px;
    min-height: 44px;
}
.fu-decision .session-btn[aria-pressed="true"] {
    outline: 2px solid #0f766e;
    outline-offset: 1px;
}
@media (max-width: 420px) {
    .fu-decision .session-btn { flex-basis: 100%; }
}

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
.hs-pending--action {
    padding: 12px 14px;
}
.hs-pending__note {
    margin: 8px 0 0;
    font-weight: 500;
    color: #78350f;
    line-height: 1.45;
}
.hs-pending__hint {
    margin: 8px 0 0;
    font-size: 11px;
    font-weight: 500;
    color: #a16207;
}
.hs-profile-form {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 12px;
}
.hs-profile-form label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
}
.hs-profile-form label:nth-child(n+3) {
    grid-column: 1 / -1;
}
.hs-profile-form__actions {
    grid-column: 1 / -1;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 4px;
}
.hs-profile-alert {
    margin-top: 10px;
    padding: 8px 10px;
    border-radius: 8px;
    font-size: 12px;
}
.hs-profile-alert--ok {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}
.hs-profile-alert--err {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
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
.bhw-act-lead {
    margin: 0 0 14px;
    font-size: 12px;
    line-height: 1.45;
    color: #64748b;
}
.bhw-act-section + .bhw-act-section {
    margin-top: 14px;
}
.bhw-act-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.bhw-act-item__title {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.35;
}
.bhw-act-item__meta {
    margin-top: 3px;
    font-size: 11px;
    color: #64748b;
    line-height: 1.4;
}
.bhw-act-item__note {
    margin: 6px 0 0;
    font-size: 12px;
    line-height: 1.45;
    color: #334155;
    white-space: pre-wrap;
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
@media (max-width: 768px) {
    .hs-grid { grid-template-columns: 1fr; }
    html:has(.consultation-session),
    body.provider-body:has(.consultation-session) {
        height: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }
    body.provider-body:has(.consultation-session) .provider-page-body {
        min-height: 0;
        padding-left: 10px;
        padding-right: 10px;
        padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px));
    }
    body.provider-body:has(.consultation-session) .pd-header-date,
    body.provider-body:has(.consultation-session) .pd-header-clock {
        display: none;
    }
    body.provider-body:has(.consultation-session) .pd-header-page {
        font-size: 0.95rem !important;
    }
    body.provider-body:has(.consultation-session) .pd-header-page::after {
        content: " · <?= htmlspecialchars($patient['name'], ENT_QUOTES) ?>";
        font-weight: 600;
        color: #64748b;
        background: transparent;
    }
    body.provider-body.consultation-mobile-call-fullscreen:has(.consultation-session) .pd-header-page {
        display: block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        -webkit-line-clamp: unset;
    }
    .video-panel,
    .video-panel.is-call-active {
        display: flex;
        flex-direction: column;
        min-height: 0;
        flex: 1 1 auto;
    }
    /* Live mobile call: fill remaining viewport below the provider header.
       aspect-ratio and leftover min-height on the page were painting the
       large white band under a short iframe. */
    .video-shell.is-call-active {
        position: fixed;
        top: var(--mc-header-offset, var(--provider-header-h, 64px));
        right: 0;
        left: 0;
        bottom: auto;
        width: 100%;
        height: calc(100vh - var(--mc-header-offset, var(--provider-header-h, 64px)));
        height: calc(100dvh - var(--mc-header-offset, var(--provider-header-h, 64px)));
        min-height: 0;
        max-height: none;
        aspect-ratio: unset;
        border-radius: 0;
        overflow: hidden;
        z-index: 400;
        background: #0b1220;
        box-shadow: none;
        box-sizing: border-box;
    }
    body.provider-body:has(.video-shell.is-call-active) {
        overflow: hidden;
        background: #0b1220;
        overscroll-behavior: none;
    }
    body.provider-body:has(.video-shell.is-call-active) .provider-page-body,
    body.provider-body:has(.video-shell.is-call-active) .main-content.provider-main,
    body.provider-body:has(.video-shell.is-call-active) .session-page {
        min-height: 0;
        height: 100dvh;
        max-height: 100dvh;
        padding: 0 !important;
        margin: 0;
        overflow: hidden;
        background: #0b1220;
    }
    body.provider-body:has(.video-shell.is-call-active) .session-side,
    body.provider-body:has(.video-shell.is-call-active) .session-left > .session-card,
    body.provider-body:has(.video-shell.is-call-active) .video-pre-call-help,
    body.provider-body:has(.video-shell.is-call-active) .video-demo-link,
    body.provider-body:has(.video-shell.is-call-active) .scroll-ai-btn,
    body.provider-body:has(.video-shell.is-call-active) #floatingScrollAiBtn,
    body.provider-body:has(.video-shell.is-call-active) .portal-mobile-nav,
    body.provider-body:has(.video-shell.is-call-active) .mc-messages-fab,
    body.provider-body:has(.video-shell.is-call-active) .messages-fab {
        display: none !important;
    }
    .video-shell.is-call-active .active-call,
    .video-shell.is-call-active .mc-provider-video-dock,
    .video-shell.is-call-active .mc-provider-video-dock .mc-session-float-shell.is-docked,
    .video-shell.is-call-active .mc-provider-video-dock .mc-session-float-body,
    .video-shell.is-call-active .mc-provider-video-dock iframe,
    .video-shell.is-call-active .active-call iframe {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        min-height: 0;
        max-height: none;
        display: block;
        border: 0;
    }
    .video-shell.is-call-active .mobile-call-expand-btn {
        display: none !important;
    }
    .session-page {
        gap: 14px;
        min-height: 0;
    }
    .video-pre-call-help {
        font-size: 11px;
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
    .csp-card,
    .bhw-act-card {
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
    .video-shell:not(.is-call-active) .video-pre-call {
        min-height: 200px;
    }
    .session-card-header {
        align-items: flex-start;
        flex-direction: column;
    }
    .session-btn {
        min-height: 44px;
    }
    .session-chat-body {
        height: min(42dvh, 320px);
        padding: 12px;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
    }
    .chat-row {
        gap: 8px;
        margin-bottom: 10px;
        max-width: 100%;
    }
    .chat-msg-col {
        max-width: calc(100% - 38px);
    }
    .chat-row.mine .chat-msg-col {
        max-width: 100%;
    }
    .chat-msg-name {
        max-width: min(42vw, 130px);
    }
    .chat-bubble {
        max-width: min(78vw, 280px);
        font-size: 14px;
        padding: 10px 12px;
    }
    .chat-mute-tts-play {
        min-height: 44px;
        width: 100%;
        padding: 8px 10px;
    }
    .session-chat-composer {
        flex-wrap: nowrap;
        gap: 8px;
        padding: 10px;
        padding-bottom: calc(10px + env(safe-area-inset-bottom, 0px));
    }
    .session-chat-composer input {
        min-height: 44px;
        height: 44px;
        font-size: 16px;
    }
    .session-chat-composer button {
        height: 44px;
        min-height: 44px;
        min-width: 64px;
        flex-shrink: 0;
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
.video-demo-link.is-visible { display: block; }
.video-panel.is-call-active .video-demo-link {
    display: none !important;
}
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

<div class="session-page consultation-session">
    
    <!-- LEFT: Video Panel & SOAP Notes -->
    <div class="session-left">
        
        <!-- VIDEO INTERFACE -->
        <div class="video-panel" id="videoPanel">
        <div class="video-shell" id="videoInterface">
            <div class="video-shell-tools">
                <button type="button" class="video-size-btn" id="toggleVideoSizeBtn" onclick="toggleVideoShellSize()" aria-label="Expand video">Expand video</button>
                <button type="button" class="video-size-btn" id="scrollToAiBtn" onclick="scrollToClinicalSupport()">Clinical Support</button>
            </div>

            <div id="videoPreCall" class="video-pre-call">
                <div class="video-pre-call__inner">
                    <div class="video-pre-call__icon"><?= icon('video') ?></div>
                    <h2 class="video-pre-call__title">Secure Video Consultation</h2>
                    <p class="video-pre-call__patient">Patient: <?= htmlspecialchars($patient['name']) ?></p>
                    <?php if (!empty($history_view)): ?>
                    <p class="video-pre-call__prompt">This consultation is closed.</p>
                    <p class="text-xs text-muted" style="margin:0;max-width:360px;line-height:1.5;">
                        <?php if (!empty($video_history['show_completed_details'])): ?>
                        Video call details are in the <strong>Video Consultation Session</strong> panel.
                        <?php else: ?>
                        No video call was recorded for this consultation. Completing SOAP alone does not create a past video session.
                        <?php endif; ?>
                    </p>
                    <?php else: ?>
                    <p class="video-pre-call__prompt">Ready to start the consultation?</p>
                    <button type="button" onclick="startVideoCall()" class="session-btn primary video-pre-call__start" aria-label="Start video consultation"><?= icon_sm('video') ?> Start Video Consultation</button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Active Call UI (hidden initially) -->
            <div id="activeCallUI" class="active-call">
                <div id="mcProviderVideoDock" class="mc-provider-video-dock" aria-label="Live video consultation"></div>
                <iframe id="videoFrame" src="" hidden allow="camera; microphone; display-capture; autoplay; fullscreen" allowfullscreen></iframe>
            </div>

            <button type="button" id="mobileCallExpandBtn" class="mobile-call-expand-btn" hidden aria-label="Expand video" onclick="toggleMobileCallFullscreen()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
            </button>

            <!-- Session Status (hidden during active call; iframe owns status UI) -->
            <div class="session-status" hidden aria-hidden="true">
                <span id="callStatusIndicator" style="color: #64748b; margin-right: 5px;">● READY</span> <span id="sessionTimer">00:00:00</span>
            </div>
        </div>

        <div id="videoPreCallHelp" class="video-pre-call-help"<?= !empty($history_view) ? ' hidden' : '' ?>>
            <p style="margin:0 0 6px;"><strong>How to start</strong></p>
            <ol>
                <li>Click <strong>Start Video Consultation</strong> to open the live room.</li>
                <li>The patient will see <strong>Join Call</strong> on their dashboard.</li>
                <li>Wait until both sides show <strong>Connected</strong> before speaking.</li>
            </ol>
            <?php if ($show_video_demo_tip): ?>
            <div class="video-demo-tip" id="videoDemoTip" style="margin-top:12px;">
                <strong>Local demo — 2 tabs on this laptop</strong>
                <ol>
                    <li><strong>Tab 1 (here):</strong> Click <strong>Start Video Consultation</strong>.</li>
                    <li><strong>Tab 2:</strong> Incognito â†’ log in as <strong>patient</strong> â†’ Dashboard.</li>
                    <li>One webcam: provider uses camera; patient can use <strong>Join with audio only</strong>.</li>
                </ol>
            </div>
            <?php endif; ?>
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
        </div>

        <div class="session-card" id="videoConsultationSessionCard">
            <div class="session-card-header">
                <div class="session-card-title"><?= icon('video') ?> Video Consultation Session</div>
            </div>
            <div class="session-card-body">
                <?php if (!empty($video_history['show_completed_details'])): ?>
                <div class="info-row"><span class="info-key">Status</span><span class="info-val">Completed</span></div>
                <div class="info-row"><span class="info-key">Date</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['date_label'] ?? '—')) ?></span></div>
                <div class="info-row"><span class="info-key">Started</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['started_label'] ?? '—')) ?></span></div>
                <div class="info-row"><span class="info-key">Ended</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['ended_label'] ?? '—')) ?></span></div>
                <div class="info-row"><span class="info-key">Duration</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['duration_label'] ?? '—')) ?></span></div>
                <?php if (!empty($video_history['participants_label'])): ?>
                <div class="info-row"><span class="info-key">Participants</span><span class="info-val"><?= htmlspecialchars((string) $video_history['participants_label']) ?></span></div>
                <?php endif; ?>
                <div class="info-row"><span class="info-key">Session status</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['session_outcome_label'] ?? 'Successfully completed')) ?></span></div>
                <?php if (!empty($video_history['timeline']) && is_array($video_history['timeline'])): ?>
                <div style="margin-top:12px;">
                    <strong style="font-size:12px;color:#0f766e;">Timeline</strong>
                    <ul style="margin:8px 0 0;padding-left:18px;font-size:12px;color:#334155;line-height:1.6;">
                        <?php foreach ($video_history['timeline'] as $ev): ?>
                        <li><?= htmlspecialchars((string) ($ev['label'] ?? '')) ?> — <?= htmlspecialchars((string) ($ev['time_label'] ?? '')) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php
                  $consultation_id = (int) $consultation_id;
                  $video_history = $video_history;
                  $recording_btn_class = 'session-btn primary';
                  require BASE_PATH . '/resources/views/partials/consultation_recording_panel.php';
                ?>
                <?php else: ?>
                <div class="info-row"><span class="info-key">Video consultation</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['video_status_label'] ?? 'Not started')) ?></span></div>
                <?php if (!empty($video_history['started_label'])): ?>
                <div class="info-row"><span class="info-key">Started</span><span class="info-val"><?= htmlspecialchars((string) $video_history['started_label']) ?></span></div>
                <?php endif; ?>
                <?php
                  $recording_btn_class = 'session-btn primary';
                  require BASE_PATH . '/resources/views/partials/consultation_recording_panel.php';
                ?>
                <?php if (!empty($history_view) && empty($video_history['has_session']) && empty($video_history['has_recording'])): ?>
                <p class="text-xs text-muted" style="margin-top:10px;line-height:1.5;">No past video call exists for this consultation in the database. A video session appears here only after <strong>Start Video Consultation</strong> runs and the call is ended.</p>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($history_view)): ?>
                <p class="text-xs text-muted" style="margin-top:12px;">Read-only history view for a closed consultation.</p>
                <a href="<?= ASSET_BASE ?>/views/provider/consultation_history.php?patient_id=<?= (int) ($c['patient_id'] ?? 0) ?>" class="session-btn" style="width:100%;margin-top:8px;text-align:center;">Back to Consultation History</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- SOAP ENCODING FORM -->
        <div class="session-card" id="soapDocumentation">
            <div class="session-card-header">
                <div class="session-card-title"><?= icon('file') ?> Clinical Documentation (SOAP)</div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <?php if ($consultation_completed): ?>
                    <span class="session-btn" style="background:#dcfce7;color:#166534;border:1px solid #86efac;cursor:default;">âœ“ SOAP Finalized</span>
                    <?php else: ?>
                    <button class="session-btn primary" type="button" onclick="saveSOAP()">Save Draft</button>
                    <button class="session-btn" type="button" onclick="document.getElementById('soapForm').reset()">Clear</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="session-card-body">
                <?php if ($consultation_completed): ?>
                <p class="text-sm" style="margin:0 0 16px;padding:12px 14px;border-radius:10px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;">
                    This consultation has been finalized. SOAP notes are read-only. The patient can view this record in My Health.
                </p>
                <?php endif; ?>
                <form id="soapForm">
                    <input type="hidden" name="consultation_id" value="<?= $consultation_id ?>">
                    <input type="hidden" name="patient_id" value="<?= (int)$c['patient_id'] ?>">
                    
                    <div class="soap-grid">
                        <div>
                            <label class="pd-label">Subjective</label>
                            <textarea name="subjective" class="pd-textarea" placeholder="Chief complaint, history of present illness..."<?= $soap_readonly ? ' readonly' : '' ?>><?= htmlspecialchars((string) ($clinical_note['subjective'] ?? '')) ?></textarea>
                        </div>
                        <div>
                            <label class="pd-label">Objective</label>
                            <textarea name="objective" class="pd-textarea" placeholder="Vital signs, physical exam findings..."<?= $soap_readonly ? ' readonly' : '' ?>><?= htmlspecialchars((string) ($clinical_note['objective'] ?? '')) ?></textarea>
                        </div>
                        <div>
                            <label class="pd-label">Assessment</label>
                            <textarea name="assessment" class="pd-textarea" placeholder="Differential diagnosis, clinical reasoning..."<?= $soap_readonly ? ' readonly' : '' ?>><?= htmlspecialchars((string) ($clinical_note['assessment'] ?? '')) ?></textarea>
                        </div>
                        <div>
                            <label class="pd-label">Plan</label>
                            <textarea name="plan" class="pd-textarea" placeholder="Management, medications, follow-up..."<?= $soap_readonly ? ' readonly' : '' ?>><?= htmlspecialchars((string) ($clinical_note['plan'] ?? '')) ?></textarea>
                        </div>
                    </div>

                    <hr style="border: 0; border-top: 1px solid #e2edf1; margin: 20px 0;">

                    <div class="soap-grid">
                        <div>
                            <label class="pd-label">Final Diagnosis</label>
                            <textarea name="diagnosis" class="pd-textarea" placeholder="ICD-10 or clinical diagnosis..."<?= $soap_readonly ? ' readonly' : '' ?>><?= htmlspecialchars((string) ($clinical_note['diagnosis'] ?? '')) ?></textarea>
                        </div>
                        <div>
                            <label class="pd-label">Digital Prescription</label>
                            <textarea name="prescription" class="pd-textarea" placeholder="Medication, Dosage, Frequency, Duration..."<?= $soap_readonly ? ' readonly' : '' ?>><?= htmlspecialchars((string) ($clinical_note['prescription'] ?? '')) ?></textarea>
                        </div>
                    </div>

                    <?php if (!$consultation_completed): ?>
                    <div class="soap-sign" id="soapSignature">
                        <h3 class="soap-sign__title">Electronic Signature</h3>
                        <p class="soap-sign__lead">Choose how you want to sign. The signature must belong to your authenticated provider account.</p>

                        <div class="soap-sign__methods" role="radiogroup" aria-label="Signature method">
                            <label class="soap-sign__method is-active">
                                <input type="radio" name="signature_method" value="typed" checked>
                                Type Full Name
                            </label>
                            <label class="soap-sign__method">
                                <input type="radio" name="signature_method" value="drawn">
                                Draw Signature
                            </label>
                        </div>

                        <div class="soap-sign__panel" id="soapTypedPanel">
                            <label class="pd-label" for="soapTypedName">Full Name</label>
                            <input type="text" id="soapTypedName" name="signature_name" class="pd-input" autocomplete="off" inputmode="text" maxlength="120" placeholder="<?= htmlspecialchars($soap_signer['full_name'] !== '' ? $soap_signer['full_name'] : 'Your registered name') ?>" value="">
                            <p class="soap-sign__hint">Type your registered name (for example <?= htmlspecialchars($soap_signer['full_name'] !== '' ? $soap_signer['full_name'] : 'First Last') ?>). It must match your provider account.</p>
                        </div>

                        <div class="soap-sign__panel" id="soapDrawnPanel" hidden>
                            <p class="pd-label">Sign below</p>
                            <div class="soap-sign__canvas-wrap" id="soapCanvasWrap">
                                <canvas id="soapSignatureCanvas" class="soap-sign__canvas" width="600" height="180" aria-label="Draw your signature"></canvas>
                                <span class="soap-sign__placeholder" id="soapCanvasHint">Sign here</span>
                            </div>
                            <div class="soap-sign__actions">
                                <button type="button" class="session-btn soap-sign__clear" id="soapClearSignature">Clear Signature</button>
                            </div>
                        </div>

                        <input type="hidden" name="signature_data" id="soapSignatureData" value="">

                        <label class="soap-sign__confirm">
                            <input type="checkbox" name="soap_confirm" id="soapConfirm" value="1">
                            <span>I confirm that I reviewed and completed this SOAP note.</span>
                        </label>

                        <button type="button" class="session-btn primary soap-sign__finalize" id="soapFinalizeBtn" disabled>Finalize SOAP Note</button>
                        <p class="soap-sign__error" id="soapSignError" role="alert"></p>
                    </div>
                    <?php else: ?>
                    <div class="soap-sign">
                        <p class="soap-sign__done">
                            <?php if ($soap_signed_by !== ''): ?>
                                <?= htmlspecialchars($soap_signed_by) ?><?php if ($soap_signed_at !== ''): ?><br><?= htmlspecialchars($soap_signed_at) ?><?php endif; ?>
                            <?php else: ?>
                                This SOAP note has been electronically signed and finalized.
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
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
                            'emergency' => 'EMERGENCY',
                            'urgent' => 'URGENT',
                            'non_urgent', 'routine' => 'NON-URGENT',
                            default => (string) ($clinical_support['risk_level'] ?? 'Not assessed'),
                        };
                    }
                    $aiUrgency = (string) ($clinical_support['ai_urgency'] ?? '');
                    if ($aiUrgency === '') {
                        $aiUrgency = !empty($clinical_support['manual_urgency']) ? 'Not assessed' : $finalUrgency;
                    }
                    $aiBucket = preg_replace('/[^a-z_]/', '', strtolower((string) ($clinical_support['ai_urgency_bucket'] ?? 'unknown'))) ?: 'unknown';
                    $doctorOverrideLabel = !empty($clinical_support['manual_urgency'])
                        ? (string) ($clinical_support['doctor_urgency'] ?? $finalUrgency)
                        : 'Not saved';
                    $clinicalReason = (string) ($clinical_support['manual_override_note'] ?? '');
                    ?>
                    <div class="csp-triage-stack">
                        <div class="csp-triage-row csp-triage-row--ai">
                            <div class="csp-risk__label">AI-Assessed Risk Level</div>
                            <div class="csp-triage-row__value">
                                <span class="csp-badge csp-badge--<?= htmlspecialchars($aiBucket) ?>" id="cspRiskBadge"><?= htmlspecialchars($aiUrgency ?: 'Not assessed') ?></span>
                                <span class="csp-risk__value" id="cspRiskLevel" hidden><?= htmlspecialchars($aiUrgency ?: 'Not assessed') ?></span>
                            </div>
                        </div>
                        <div class="csp-triage-row csp-triage-row--doctor">
                            <div class="csp-risk__label">Doctor Urgency Override</div>
                            <div class="csp-final-urgency" id="cspDoctorOverrideValue"><?= htmlspecialchars($doctorOverrideLabel) ?></div>
                        </div>
                        <div class="csp-triage-row csp-triage-row--reason" id="cspClinicalReasonWrap" <?= $clinicalReason === '' ? 'hidden' : '' ?>>
                            <div class="csp-risk__label">Clinical Reason</div>
                            <p class="csp-reason-text" id="cspClinicalReasonValue"><?= htmlspecialchars($clinicalReason) ?></p>
                        </div>
                        <div class="csp-triage-row csp-triage-row--final">
                            <div class="csp-risk__label">Final Triage Result</div>
                            <div class="csp-final-urgency" id="cspFinalUrgency"><?= htmlspecialchars($finalUrgency) ?></div>
                        </div>
                    </div>
                    <p class="csp-meta" style="margin-top:6px;border:0;padding:0;" id="cspOverrideNote">
                        <?php if (!empty($clinical_support['manual_urgency'])): ?>
                            Authoritative final result is the saved doctor override.
                        <?php elseif (!empty($clinical_support['doctor_override'])): ?>
                            Based on doctor-finalized chief complaint
                        <?php else: ?>
                            Based on pre-consult triage (save an override above to update the final result)
                        <?php endif; ?>
                    </p>
                    <div class="csp-section" id="cspAiPreliminaryBlock">
                        <h4 class="csp-section__title">AI Preliminary Triage</h4>
                        <p class="csp-empty" style="font-style:normal;margin:0 0 8px;">
                            Classification:
                            <strong><?= htmlspecialchars((string) ($clinical_support['ai_urgency'] ?? 'Not assessed')) ?></strong>
                            <?php if (!empty($clinical_support['assessed_label'])): ?>
                            <span style="color:#64748b;font-weight:400;"> · Assessment time: <?= htmlspecialchars((string) $clinical_support['assessed_label']) ?></span>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($clinical_support['detected_complaints'])): ?>
                        <p style="margin:0 0 6px;font-size:13px;"><strong>Detected complaints:</strong> <?= htmlspecialchars(implode(', ', (array) $clinical_support['detected_complaints'])) ?></p>
                        <?php endif; ?>
                        <?php if (isset($clinical_support['pain_score']) && $clinical_support['pain_score'] !== null && $clinical_support['pain_score'] !== ''): ?>
                        <p style="margin:0 0 6px;font-size:13px;"><strong>Pain score:</strong> <?= htmlspecialchars((string) $clinical_support['pain_score']) ?>/10</p>
                        <?php endif; ?>
                        <?php if (!empty($clinical_support['associated_symptoms'])): ?>
                        <p style="margin:0 0 6px;font-size:13px;"><strong>Associated symptoms:</strong> <?= htmlspecialchars(implode(', ', (array) $clinical_support['associated_symptoms'])) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($clinical_support['relevant_red_flags'])): ?>
                        <p style="margin:0;font-size:13px;"><strong>Relevant red flags:</strong> <?= htmlspecialchars(implode(', ', (array) $clinical_support['relevant_red_flags'])) ?></p>
                        <?php endif; ?>
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
                            <strong>Primary Complaint:</strong> <span id="cspComplaintText"><?= htmlspecialchars($clinical_support['chief_complaint']) ?></span>
                            <span id="cspEnglishWrap" <?= ($clinical_support['english_complaint'] === '' || strcasecmp($clinical_support['english_complaint'], $clinical_support['chief_complaint']) === 0) ? 'hidden' : '' ?>>
                                <br><span style="color:#64748b;">English: <span id="cspEnglishText"><?= htmlspecialchars($clinical_support['english_complaint']) ?></span></span>
                            </span>
                        </p>
                        <?php if (!empty($clinical_support['registration_complaint_reference'])): ?>
                        <p id="cspRegistrationRefLine" style="margin: 10px 0 0; font-size: 12px; color: #64748b; line-height: 1.45;">
                            <strong>Registration reference:</strong>
                            <span id="cspRegistrationRefText"><?= htmlspecialchars((string) $clinical_support['registration_complaint_reference']) ?></span>
                        </p>
                        <?php endif; ?>
                        <?php if (!empty($clinical_support['current_complaint_submitted_at'])): ?>
                        <p id="cspComplaintSubmittedLine" style="margin: 6px 0 0; font-size: 12px; color: #64748b;">
                            Current complaint submitted: <span id="cspComplaintSubmittedAt"><?= htmlspecialchars((string) $clinical_support['current_complaint_submitted_at']) ?></span>
                        </p>
                        <?php endif; ?>
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
                                            <br>Primary Complaint: <?= htmlspecialchars(strlen($entry['chief_complaint']) > 120 ? substr($entry['chief_complaint'], 0, 117) . '…' : $entry['chief_complaint']) ?>
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
                <button type="button" class="session-btn primary" id="sessionSendBtn">Send</button>
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

                <?php
                $pending_hs = $health_summary['pending_request'] ?? null;
                if (!empty($pending_hs)):
                    $pending_note = trim((string) ($pending_hs['patient_note'] ?? ''));
                    $proposed = $pending_hs['proposed'] ?? [];
                    $hs_blood = trim((string) ($proposed['blood_type'] ?? '')) ?: trim((string) ($health_summary['blood_type'] ?? $c['blood_type'] ?? ''));
                    $hs_allergies = trim((string) ($proposed['allergies'] ?? '')) ?: trim((string) ($c['allergies'] ?? ''));
                    $hs_conditions = trim((string) ($proposed['existing_conditions'] ?? '')) ?: trim((string) ($c['existing_conditions'] ?? ''));
                    $hs_medications = trim((string) ($proposed['current_medications'] ?? '')) ?: trim((string) ($c['current_medications'] ?? ''));
                ?>
                <div class="hs-pending hs-pending--action" id="sessionMedicalUpdateCard"
                     data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"
                     data-patient-id="<?= (int) $c['patient_id'] ?>"
                     data-request-id="<?= (int) ($pending_hs['id'] ?? 0) ?>">
                    <strong>Patient requested a Health Summary update</strong>
                    <?php if ($pending_note !== ''): ?>
                    <p class="hs-pending__note"><?= htmlspecialchars($pending_note) ?></p>
                    <?php endif; ?>
                    <p class="hs-pending__hint">Review requested values, edit if needed, then approve or reject. Official Health Summary updates only after approval.</p>
                    <form id="sessionMedicalProfileForm" class="hs-profile-form">
                        <label>Blood type
                            <select name="blood_type" class="pd-input">
                                <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'] as $bt): ?>
                                <option value="<?= $bt ?>" <?= $hs_blood === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Allergies<textarea name="allergies" class="pd-input" rows="2"><?= htmlspecialchars($hs_allergies) ?></textarea></label>
                        <label>Conditions<textarea name="existing_conditions" class="pd-input" rows="2"><?= htmlspecialchars($hs_conditions) ?></textarea></label>
                        <label>Medications<textarea name="current_medications" class="pd-input" rows="2"><?= htmlspecialchars($hs_medications) ?></textarea></label>
                        <div class="hs-profile-form__actions">
                            <button type="submit" class="session-btn primary">Approve &amp; Update</button>
                            <button type="button" class="session-btn" id="sessionMedicalRejectBtn">Reject</button>
                            <a href="<?= ASSET_BASE ?>/views/provider/medical_records.php?patient_id=<?= (int) $c['patient_id'] ?>" class="session-btn">Medical Records</a>
                        </div>
                    </form>
                    <div id="sessionMedicalProfileAlert" class="hs-profile-alert" hidden role="alert"></div>
                </div>
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
                    <strong>Primary Complaint (this visit)</strong>
                    <?= htmlspecialchars($patient['complaint']) ?>
                </div>

                <p class="hs-meta">
                    Last updated:
                    <?= htmlspecialchars((string) ($health_summary['metadata']['last_updated_at_label'] ?? 'Not available')) ?>
                    · <?= htmlspecialchars((string) ($health_summary['metadata']['last_updated_by'] ?? 'Registration')) ?>
                </p>
            </div>
        </div>

        <?php require VIEWS_PATH . '/partials/bhw_activity_panel.php'; ?>

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

<div id="cspEmergencyModal" class="csp-emergency-modal" hidden role="dialog" aria-modal="true" aria-labelledby="cspEmergencyTitle">
    <div class="csp-emergency-modal__backdrop" data-csp-emergency-close></div>
    <div class="csp-emergency-modal__card">
        <p class="csp-emergency-modal__eyebrow">Final triage confirmed</p>
        <h2 class="csp-emergency-modal__title" id="cspEmergencyTitle">EMERGENCY CASE</h2>
        <p class="csp-emergency-modal__lead" id="cspEmergencyLead">This is not a video visit. The patient must go to the nearest hospital or emergency department now. A hospital referral has been recorded, and the patient is being shown where to go.</p>
        <dl class="csp-emergency-modal__dl">
            <dt>Patient name</dt>
            <dd id="cspEmergencyPatientName"><?= htmlspecialchars($patient['name']) ?></dd>
            <dt>Patient ID</dt>
            <dd id="cspEmergencyPatientId"><?= htmlspecialchars($patient['patient_number']) ?></dd>
            <dt>Consultation ID</dt>
            <dd id="cspEmergencyConsultId"><?= (int) $consultation_id ?></dd>
            <dt>Final urgency</dt>
            <dd id="cspEmergencyFinal">EMERGENCY</dd>
            <dt>Clinical reason</dt>
            <dd id="cspEmergencyReason">—</dd>
        </dl>
        <div class="csp-emergency-modal__facility" id="cspEmergencyFacility" hidden>
            <p class="csp-emergency-modal__facility-heading">Nearest facility shown to patient</p>
            <strong class="csp-emergency-modal__facility-name" id="cspEmergencyFacilityName"></strong>
            <p class="csp-emergency-modal__facility-meta" id="cspEmergencyFacilityMeta"></p>
        </div>
        <p class="csp-emergency-modal__status" id="cspEmergencyConnStatus">Patient notified to seek emergency in-person care.</p>
        <div class="csp-emergency-modal__actions">
            <button type="button" class="session-btn primary" id="cspEmergencyConnectBtn" hidden>Return to live call</button>
            <button type="button" class="session-btn" data-csp-emergency-close>Close</button>
        </div>
    </div>
</div>

<button type="button" class="scroll-ai-btn" id="floatingScrollAiBtn" onclick="scrollToClinicalSupport()">Clinical Support</button>

<!-- Post-call follow-up modal -->
<div id="followUpModal" class="fu-modal" aria-hidden="true">
  <div class="fu-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="followUpModalTitle">
    <div class="fu-modal__header">
      <p class="fu-modal__eyebrow">After video consultation</p>
      <h2 id="followUpModalTitle" class="fu-modal__title">Schedule patient follow-up</h2>
    </div>
    <div class="fu-modal__body">
      <div class="fu-field">
        <label>Patient</label>
        <div class="fu-contact"><?= htmlspecialchars($patient['name']) ?></div>
      </div>

      <div class="fu-field" id="fuDecisionStep">
        <label id="fuDecisionLabel">Follow-up required?</label>
        <div class="fu-decision" role="group" aria-labelledby="fuDecisionLabel">
          <button type="button" class="session-btn primary" id="fuDecisionYes" aria-pressed="false">Yes, schedule follow-up</button>
          <button type="button" class="session-btn" id="fuDecisionNo" aria-pressed="false">No follow-up needed</button>
        </div>
      </div>

      <div class="fu-field" id="fuSlotStep" hidden>
        <label for="fuSlotSelect">Available follow-up times</label>
        <select id="fuSlotSelect" class="pd-input" style="width:100%;">
          <option value="">Loading your available slots…</option>
        </select>
        <p class="fu-hint" id="fuSlotHint">Only real openings from your own schedule are listed.</p>
      </div>

      <div class="fu-field" id="fuNotesStep" hidden>
        <label for="fuFollowUpMessage">Notes for the patient (optional)</label>
        <textarea id="fuFollowUpMessage" class="pd-textarea" rows="3" placeholder="Follow-up instructions for the patient…"></textarea>
      </div>

      <p id="fuModalStatus" class="fu-status" aria-live="polite"></p>
    </div>
    <div class="fu-modal__footer">
      <button type="button" class="session-btn" id="fuSkipBtn">Decide later</button>
      <button type="button" class="session-btn primary" id="fuSaveBtn" hidden>Save follow-up</button>
    </div>
  </div>
</div>

<div id="soapFinalizeModal" class="soap-finalize-modal" aria-hidden="true">
  <div class="soap-finalize-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="soapFinalizeTitle">
    <div class="soap-finalize-modal__body">
      <h2 id="soapFinalizeTitle" class="soap-finalize-modal__title">Finalize SOAP Note?</h2>
      <p class="soap-finalize-modal__text">You are about to electronically sign and finalize this clinical record. After finalization, the SOAP note will become available to the patient and ordinary editing will be disabled.</p>
    </div>
    <div class="soap-finalize-modal__footer">
      <button type="button" class="session-btn" id="soapFinalizeCancel">Cancel</button>
      <button type="button" class="session-btn primary" id="soapFinalizeConfirm">Confirm &amp; Finalize</button>
    </div>
  </div>
</div>

<div id="soapSuccessModal" class="soap-finalize-modal soap-success-modal" aria-hidden="true">
  <div class="soap-finalize-modal__dialog soap-success-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="soapSuccessTitle">
    <div class="soap-finalize-modal__body soap-success-modal__body">
      <div class="soap-success-modal__icon" aria-hidden="true">
        <svg viewBox="0 0 48 48" width="48" height="48" fill="none">
          <circle cx="24" cy="24" r="22" fill="#ecfdf5" stroke="#86efac" stroke-width="2"/>
          <path d="M15 24.5l5.5 5.5L33 17.5" stroke="#059669" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <p class="soap-success-modal__eyebrow">Consultation #<?= (int) $consultation_id ?></p>
      <h2 id="soapSuccessTitle" class="soap-finalize-modal__title">SOAP note finalized</h2>
      <p id="soapSuccessText" class="soap-finalize-modal__text">This clinical record is now saved. The patient can view it in My Health, and it appears in your Consultation History.</p>
      <ul class="soap-success-modal__list">
        <li>Record is read-only</li>
        <li>Available in patient My Health</li>
        <li>Saved to Consultation History</li>
      </ul>
    </div>
    <div class="soap-finalize-modal__footer soap-success-modal__footer">
      <button type="button" class="session-btn" id="soapSuccessStay">View this record</button>
      <a href="<?= ASSET_BASE ?>/views/provider/consultation_history.php?patient_id=<?= (int) ($c['patient_id'] ?? 0) ?>" class="session-btn primary" id="soapSuccessHistory">View Consultation History</a>
    </div>
  </div>
</div>

<script src="<?= ASSET_BASE ?>/assets/js/messages-delete.js?v=3"></script>
<?php $soapSigJsVer = (int) @filemtime(ASSETS_PATH . '/js/soap-signature.js'); ?>
<script src="<?= ASSET_BASE ?>/assets/js/soap-signature.js?v=<?= $soapSigJsVer ?: time() ?>"></script>
<script>
// SESSION TIMER
let seconds = 0;
let timerActive = false;
let mobileCallFullscreen = false;
let desktopVideoExpanded = false;
let trueCallFullscreen = false;
let videoCallClosed = <?= !empty($history_view) ? 'true' : 'false' ?>;
let lastEmergencyVideoSessionActive = false;
const MOBILE_CONSULT_BREAK = 768;

function isMobileConsultation() {
    return window.matchMedia && window.matchMedia('(max-width: ' + MOBILE_CONSULT_BREAK + 'px)').matches;
}

function updateMobileExpandBtn() {
    const btn = document.getElementById('mobileCallExpandBtn');
    if (!btn) return;
    const shell = document.getElementById('videoInterface');
    const active = shell && shell.classList.contains('is-call-active');
    btn.hidden = !active || mobileCallFullscreen;
    btn.setAttribute('aria-label', mobileCallFullscreen ? 'Exit fullscreen' : 'Expand video');
}

function enterMobileCallFullscreen() {
    const shell = document.getElementById('videoInterface');
    if (!shell || !shell.classList.contains('is-call-active')) return;
    mobileCallFullscreen = true;
    document.body.classList.add('consultation-mobile-call-fullscreen');
    shell.classList.add('is-mobile-fullscreen');
    /* CSS-only: native Fullscreen API needs a parent user-gesture and exiting
       OS fullscreen was tearing down the in-app call layout. */
    syncVideoExpandedToFrame(true);
    updateExpandButtons();
}

function exitMobileCallFullscreen() {
    const shell = document.getElementById('videoInterface');
    if (trueCallFullscreen) {
        trueCallFullscreen = false;
        document.body.classList.remove('consultation-true-fullscreen');
        syncTrueFullscreenToFrame(false);
    }
    mobileCallFullscreen = false;
    document.body.classList.remove('consultation-mobile-call-fullscreen');
    if (shell) shell.classList.remove('is-mobile-fullscreen');
    if (document.fullscreenElement || document.webkitFullscreenElement) {
        const exit = document.exitFullscreen || document.webkitExitFullscreen;
        if (exit) exit.call(document).catch(function () {});
    }
    syncVideoExpandedToFrame(false);
    updateExpandButtons();
}

function toggleMobileCallFullscreen() {
    if (mobileCallFullscreen) exitMobileCallFullscreen();
    else enterMobileCallFullscreen();
}

function syncTrueFullscreenMetrics() {
    const vv = window.visualViewport;
    const h = vv && vv.height ? vv.height : window.innerHeight;
    document.documentElement.style.setProperty('--mc-true-fs-height', Math.round(h) + 'px');
}

function syncTrueFullscreenToFrame(expanded) {
    const win = mcProviderVideoWindow();
    if (!win) return;
    try {
        win.postMessage({
            type: 'medconnect:true-fullscreen-state',
            expanded: !!expanded,
        }, window.location.origin);
    } catch (e) {}
}

function enterTrueCallFullscreen() {
    const shell = document.getElementById('videoInterface');
    if (!shell || !shell.classList.contains('is-call-active')) return;
    if (trueCallFullscreen) {
        syncTrueFullscreenMetrics();
        syncTrueFullscreenToFrame(true);
        return;
    }
    trueCallFullscreen = true;
    if (!mobileCallFullscreen) enterMobileCallFullscreen();
    document.body.classList.add('consultation-true-fullscreen');
    syncTrueFullscreenMetrics();
    syncTrueFullscreenToFrame(true);
    updateExpandButtons();
}

function exitTrueCallFullscreen() {
    if (!trueCallFullscreen) return;
    trueCallFullscreen = false;
    document.body.classList.remove('consultation-true-fullscreen');
    if (document.fullscreenElement || document.webkitFullscreenElement) {
        const exit = document.exitFullscreen || document.webkitExitFullscreen;
        if (exit) {
            try { exit.call(document); } catch (e) {}
        }
    }
    syncTrueFullscreenToFrame(false);
    updateExpandButtons();
}

function toggleTrueCallFullscreen(expanded) {
    if (typeof expanded === 'boolean') {
        if (expanded) enterTrueCallFullscreen();
        else exitTrueCallFullscreen();
        return;
    }
    if (trueCallFullscreen) exitTrueCallFullscreen();
    else enterTrueCallFullscreen();
}

function syncVideoExpandedToFrame(expanded) {
    const win = mcProviderVideoWindow();
    if (!win) return;
    try {
        win.postMessage({
            type: 'medconnect:mobile-fullscreen-state',
            expanded: !!expanded,
        }, window.location.origin);
        win.postMessage({
            type: 'medconnect:workspace-expanded-state',
            expanded: !!expanded,
        }, window.location.origin);
    } catch (e) {}
}

function updateExpandButtons() {
    updateMobileExpandBtn();
    const toolsBtn = document.getElementById('toggleVideoSizeBtn');
    if (!toolsBtn) return;
    const expanded = desktopVideoExpanded || mobileCallFullscreen;
    toolsBtn.textContent = expanded ? 'Restore video' : 'Expand video';
    toolsBtn.setAttribute('aria-label', expanded ? 'Restore video consultation' : 'Maximize video consultation');
    toolsBtn.title = expanded ? 'Restore video' : 'Expand video';
}

function enterDesktopVideoExpanded() {
    const shell = document.getElementById('videoInterface');
    if (!shell || !shell.classList.contains('is-call-active')) return;
    if (isMobileConsultation()) {
        enterMobileCallFullscreen();
        return;
    }
    desktopVideoExpanded = true;
    document.body.classList.add('consultation-desktop-video-expanded');
    const dock = document.getElementById('mcProviderVideoDock');
    if (window.McSessionVideoShell && McSessionVideoShell.isActive() && dock) {
        McSessionVideoShell.dock(dock);
    }
    const floatingBtn = document.getElementById('floatingScrollAiBtn');
    if (floatingBtn) floatingBtn.classList.remove('show');
    syncVideoExpandedToFrame(true);
    updateExpandButtons();
}

function exitDesktopVideoExpanded() {
    desktopVideoExpanded = false;
    document.body.classList.remove('consultation-desktop-video-expanded');
    const dock = document.getElementById('mcProviderVideoDock');
    if (window.McSessionVideoShell && McSessionVideoShell.isActive() && dock) {
        McSessionVideoShell.dock(dock);
    }
    const shell = document.getElementById('videoInterface');
    const floatingBtn = document.getElementById('floatingScrollAiBtn');
    if (floatingBtn) {
        floatingBtn.classList.toggle('show', !!(shell && shell.classList.contains('is-call-active')));
    }
    syncVideoExpandedToFrame(false);
    updateExpandButtons();
}

function toggleDesktopVideoExpanded() {
    if (desktopVideoExpanded) exitDesktopVideoExpanded();
    else enterDesktopVideoExpanded();
}

function markVideoCallClosed() {
    videoCallClosed = true;
    const preCall = document.getElementById('videoPreCall');
    if (preCall) preCall.style.display = 'flex';
    const title = document.querySelector('.video-pre-call__title');
    const prompt = document.querySelector('.video-pre-call__prompt');
    const startBtn = document.querySelector('.video-pre-call__start');
    if (title) title.textContent = 'Consultation ended';
    if (prompt) prompt.textContent = 'The video call has ended. Complete SOAP documentation below. This session cannot be restarted.';
    if (startBtn) startBtn.hidden = true;
    const help = document.getElementById('videoPreCallHelp');
    if (help) help.hidden = true;
}

window.addEventListener('resize', function () {
    syncTrueFullscreenMetrics();
    if (!document.getElementById('videoInterface') || !document.getElementById('videoInterface').classList.contains('is-call-active')) {
        return;
    }
    if (desktopVideoExpanded && isMobileConsultation()) {
        exitDesktopVideoExpanded();
        enterMobileCallFullscreen();
        if (trueCallFullscreen) enterTrueCallFullscreen();
    } else if (mobileCallFullscreen && !isMobileConsultation()) {
        exitTrueCallFullscreen();
        exitMobileCallFullscreen();
        enterDesktopVideoExpanded();
    }
});

if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', syncTrueFullscreenMetrics);
    window.visualViewport.addEventListener('scroll', syncTrueFullscreenMetrics);
}

window.addEventListener('orientationchange', function () {
    window.setTimeout(syncTrueFullscreenMetrics, 250);
});

document.addEventListener('fullscreenchange', onParentNativeFullscreenChange);
document.addEventListener('webkitfullscreenchange', onParentNativeFullscreenChange);

let parentHadNativeFs = false;
function onParentNativeFullscreenChange() {
    const shell = document.getElementById('videoInterface');
    const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
    if (!shell || !shell.classList.contains('is-call-active')) return;
    if (fsEl) {
        parentHadNativeFs = true;
        if (!trueCallFullscreen) enterTrueCallFullscreen();
        return;
    }
    if (parentHadNativeFs) {
        parentHadNativeFs = false;
        if (trueCallFullscreen) exitTrueCallFullscreen();
    }
}
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

/**
 * The video room iframe runs its own speech queue. While it is open this page must
 * stay silent or the provider hears every typed-voice message twice, since the two
 * documents have separate speechSynthesis contexts and separate dedup sets.
 */
function callFrameOwnsSpeech() {
    const shellFrame = document.getElementById('mcGlobalVideoFrame');
    if (shellFrame && String(shellFrame.src || '').indexOf('video_room.php') !== -1) return true;
    const legacy = document.getElementById('videoFrame');
    if (legacy && !legacy.hidden && String(legacy.src || '').indexOf('video_room.php') !== -1) return true;
    return false;
}

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
    if (!force && callFrameOwnsSpeech()) return;
    // Same read-aloud preference the in-call panel writes.
    if (!force) {
        try {
            if (window.localStorage.getItem('mc_tts_read_aloud') === '0') return;
        } catch (e) { /* preference unavailable; keep speaking */ }
    }
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
            ? `<button type="button" class="chat-mute-tts-play" data-play-mute-tts="${Number(message.id)}">â–¶ Play Audio</button>`
            : '';
        row.innerHTML = `
            <div class="chat-avatar">${escapeChatHtml(mine ? sessionProviderInitials : sessionPatientInitials)}</div>
            <div class="chat-msg-col">
                <div class="chat-msg-head">
                    ${mine ? '' : '<span class="chat-msg-name">' + escapeChatHtml(message.sender_name || (mine ? 'You' : 'Patient')) + '</span>'}
                    <div class="chat-time">${escapeChatHtml(message.time || '')}</div>
                </div>
                ${MedConnectMessages.buildChatBubbleHtml(message, mine ? 'mine' : 'patient')}
                ${playBtn}
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
    if (params.get('soap') === '1' || window.location.hash === '#soapDocumentation') {
        const soapCard = document.getElementById('soapDocumentation');
        if (soapCard) {
            window.setTimeout(() => soapCard.scrollIntoView({ behavior: 'smooth', block: 'start' }), 200);
        }
        params.delete('soap');
        const next = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + (window.location.hash || '#soapDocumentation');
        window.history.replaceState({}, '', next);
    } else if (params.get('followup') === '1') {
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
            meta += '<br>Primary Complaint: ' + escapeHtml(short);
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
    const aiBucket = String(support.ai_urgency_bucket || 'unknown').replace(/[^a-z_]/g, '') || 'unknown';
    const finalUrgency = support.final_urgency || support.risk_level || 'Not assessed';
    const aiUrgency = support.ai_urgency || 'Not assessed';
    const doctorOverride = support.manual_urgency
        ? (support.doctor_urgency || finalUrgency)
        : 'Not saved';

    if (riskLevel) riskLevel.textContent = aiUrgency || 'Not assessed';
    if (riskBadge) {
        riskBadge.className = 'csp-badge csp-badge--' + (aiBucket !== 'unknown' ? aiBucket : 'unknown');
        riskBadge.textContent = aiUrgency || 'Not assessed';
    }
    const doctorEl = document.getElementById('cspDoctorOverrideValue');
    if (doctorEl) doctorEl.textContent = doctorOverride;
    const reasonWrap = document.getElementById('cspClinicalReasonWrap');
    const reasonEl = document.getElementById('cspClinicalReasonValue');
    if (reasonEl) reasonEl.textContent = support.manual_override_note || '';
    if (reasonWrap) reasonWrap.hidden = !support.manual_override_note;
    if (finalUrgencyEl) finalUrgencyEl.textContent = finalUrgency;
    if (overrideNote) {
        if (support.manual_urgency) {
            overrideNote.textContent = 'Authoritative final result is the saved doctor override.';
        } else if (support.doctor_override) {
            overrideNote.textContent = 'Based on doctor-finalized chief complaint';
        } else {
            overrideNote.textContent = 'Based on pre-consult triage (save an override above to update the final result)';
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
        const persisted = result.persisted || {};
        const gis = result.gis || persisted.gis || {};
        const finalLabel = persisted.final_label
            || (result.support && result.support.final_urgency)
            || urgency;
        const gisLabel = gis.label || '';
        if (gis.bucket && persisted.final_bucket && gis.bucket !== persisted.final_bucket) {
            if (status) {
                status.className = 'csp-status is-error';
                status.textContent = 'Override saved but GIS status did not match the doctor result.';
            }
            return;
        }
        applyClinicalSupport(result.support || {});
        if (result.audit) renderClinicalAudit(result.audit);
        if (select && persisted.final_bucket) {
            select.value = persisted.final_bucket;
        }
        if (status) {
            status.className = 'csp-status is-ok';
            status.textContent = gisLabel
                ? ('Override saved — Final Triage Result ' + finalLabel + ' · GIS ' + gisLabel)
                : ('Override saved — Final Triage Result ' + finalLabel);
        }
        const workflow = result.workflow || {};
        if ((workflow.emergency === true || workflow.emergency_triggered === true)
            && String(persisted.final_bucket || '') === 'emergency') {
            openProviderEmergencyModal(result);
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

function providerVideoUiIsOpen() {
    const activeUi = document.getElementById('activeCallUI');
    return !!(activeUi && window.getComputedStyle(activeUi).display !== 'none');
}

function emergencyVideoIsLive() {
    if (videoCallClosed) return false;
    return providerVideoUiIsOpen() || lastEmergencyVideoSessionActive === true;
}

function currentFinalBucketIsEmergency() {
    const el = document.getElementById('cspFinalUrgency');
    return String(el && el.textContent ? el.textContent : '').toUpperCase().indexOf('EMERGENCY') !== -1;
}

function updateEmergencyConnectionStatus() {
    const el = document.getElementById('cspEmergencyConnStatus');
    const btn = document.getElementById('cspEmergencyConnectBtn');
    const live = emergencyVideoIsLive();
    const patientOnCall = window.mcWebrtcPatientConnected === true;
    if (el) {
        el.classList.toggle('is-live', live && patientOnCall);
        if (live && patientOnCall) {
            el.textContent = 'A live video call is still open. Use it only to tell the patient to go to the ER, then end the visit.';
        } else if (live) {
            el.textContent = 'A video room is still open. Close this notice to return to it. Do not start a new video visit.';
        } else {
            el.textContent = 'Patient notified to seek emergency in-person care. Do not start a new video consultation.';
        }
    }
    if (btn) {
        btn.hidden = !live;
        btn.textContent = 'Return to live call';
    }
}

function openProviderEmergencyModal(result) {
    const modal = document.getElementById('cspEmergencyModal');
    if (!modal) return;
    const persisted = (result && result.persisted) || {};
    const patient = (result && result.patient) || {};
    const workflow = (result && result.workflow) || {};
    lastEmergencyVideoSessionActive = workflow.video_session_active === true;
    const nameEl = document.getElementById('cspEmergencyPatientName');
    const idEl = document.getElementById('cspEmergencyPatientId');
    const consultEl = document.getElementById('cspEmergencyConsultId');
    const finalEl = document.getElementById('cspEmergencyFinal');
    const reasonEl = document.getElementById('cspEmergencyReason');
    if (nameEl && patient.name) nameEl.textContent = patient.name;
    if (idEl && patient.patient_number) idEl.textContent = patient.patient_number;
    if (consultEl) consultEl.textContent = String(persisted.consultation_id || sessionConsultationId || '');
    if (finalEl) finalEl.textContent = persisted.final_label || 'EMERGENCY';
    if (reasonEl) reasonEl.textContent = persisted.clinical_reason || '—';

    const facilityWrap = document.getElementById('cspEmergencyFacility');
    const facilityName = document.getElementById('cspEmergencyFacilityName');
    const facilityMeta = document.getElementById('cspEmergencyFacilityMeta');
    const facilityPayload = workflow.facility && typeof workflow.facility === 'object' ? workflow.facility : {};
    const nearest = facilityPayload.facility || null;
    if (facilityWrap) {
        if (nearest && nearest.name) {
            facilityWrap.hidden = false;
            if (facilityName) facilityName.textContent = nearest.name;
            if (facilityMeta) {
                const bits = [nearest.type, nearest.distance_label, nearest.address].filter(Boolean);
                facilityMeta.textContent = bits.join(' · ');
            }
        } else {
            facilityWrap.hidden = true;
            if (facilityName) facilityName.textContent = '';
            if (facilityMeta) {
                facilityMeta.textContent = facilityPayload.message || '';
            }
            if (facilityPayload.message) {
                facilityWrap.hidden = false;
                if (facilityName) facilityName.textContent = 'Facility directory unavailable';
            }
        }
    }

    updateEmergencyConnectionStatus();
    modal.hidden = false;
}

function closeProviderEmergencyModal() {
    const modal = document.getElementById('cspEmergencyModal');
    if (modal) modal.hidden = true;
}

document.addEventListener('click', function (e) {
    const t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('#cspEmergencyConnectBtn')) {
        closeProviderEmergencyModal();
        return;
    }
    if (t.closest('[data-csp-emergency-close]')) {
        closeProviderEmergencyModal();
    }
});

function setVideoShellLive(isLive) {
    const shell = document.getElementById('videoInterface');
    const panel = document.getElementById('videoPanel');
    const floatingBtn = document.getElementById('floatingScrollAiBtn');
    const expandBtn = document.getElementById('mobileCallExpandBtn');
    if (!shell) return;
    shell.classList.toggle('is-live', !!isLive);
    shell.classList.toggle('is-call-active', !!isLive);
    if (panel) panel.classList.toggle('is-call-active', !!isLive);
    if (floatingBtn) {
        floatingBtn.classList.toggle('show', !!isLive && !mobileCallFullscreen);
    }
    if (expandBtn) {
        expandBtn.hidden = !isLive || mobileCallFullscreen;
    }
    if (!isLive) {
        exitMobileCallFullscreen();
        exitDesktopVideoExpanded();
    } else if (isMobileConsultation() && !mobileCallFullscreen) {
        enterMobileCallFullscreen();
    }
    updateExpandButtons();
}

function toggleVideoShellSize() {
    if (isMobileConsultation()) {
        toggleMobileCallFullscreen();
        return;
    }
    toggleDesktopVideoExpanded();
}

function maximizeVideoShell() {
    if (isMobileConsultation()) {
        enterMobileCallFullscreen();
        return;
    }
    enterDesktopVideoExpanded();
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
        exitMobileCallFullscreen();
        if (window.McSessionVideoShell) {
            McSessionVideoShell.close();
        }
        const legacy = document.getElementById('videoFrame');
        if (legacy) {
            legacy.src = 'about:blank';
            legacy.hidden = true;
        }

        document.getElementById('activeCallUI').style.display = 'none';
        markVideoCallClosed();
        document.getElementById('callStatusIndicator').style.color = '#64748b';
        document.getElementById('callStatusIndicator').textContent = '● ENDED';
        setVideoShellLive(false);
        // The consultation is saved server-side by end_video.php before this
        // message fires, so the follow-up decision comes next rather than
        // throwing the provider straight into SOAP.
        openFollowUpModal({ fromCallEnd: true });
        return;
    }

    if (event.data.type === 'medconnect:request-true-fullscreen') {
        toggleTrueCallFullscreen(!!event.data.expanded);
        return;
    }

    if (event.data.type === 'medconnect:minimize-video') {
        if (isMobileConsultation()) {
            exitMobileCallFullscreen();
        } else {
            exitDesktopVideoExpanded();
        }
        return;
    }

    if (event.data.type === 'medconnect:maximize-video') {
        if (isMobileConsultation()) {
            enterMobileCallFullscreen();
        } else {
            enterDesktopVideoExpanded();
        }
        return;
    }

    if (event.data.type === 'medconnect:call-state') {
        const indicator = document.getElementById('callStatusIndicator');
        const timerEl = document.getElementById('sessionTimer');
        if (indicator && event.data.statusLabel) {
            indicator.textContent = event.data.statusLabel;
            indicator.style.color = event.data.connected ? '#22c55e' : '#ef4444';
        }
        window.mcWebrtcPatientConnected = !!(event.data.connected && !event.data.patientDisconnected);
        updateEmergencyConnectionStatus();
        if (timerEl && event.data.patientDisconnected) {
            timerActive = true;
        } else if (timerEl && event.data.timerActive === false) {
            timerActive = false;
        }
        if (timerEl && event.data.timerActive === true) {
            timerActive = true;
        }
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

function mcProviderVideoWindow() {
    if (window.McSessionVideoShell) {
        const globalFrame = document.getElementById('mcGlobalVideoFrame');
        if (globalFrame && globalFrame.contentWindow) return globalFrame.contentWindow;
    }
    const frame = document.getElementById('videoFrame');
    return frame && frame.contentWindow ? frame.contentWindow : null;
}

function mcProviderOpenVideo(urlOrToken, consultationId) {
    if (videoCallClosed) {
        return '';
    }
    const token = window.McSessionVideoShell
        ? McSessionVideoShell.extractToken(urlOrToken)
        : (String(urlOrToken).match(/[?&]token=([^&]+)/) || [])[1] || String(urlOrToken);
    const joinUrl = '<?= ASSET_BASE ?>/views/consultation/video_room.php?token=' + token;

    const preCall = document.getElementById('videoPreCall');
    if (preCall) preCall.style.display = 'none';
    document.getElementById('activeCallUI').style.display = 'block';
    document.getElementById('callStatusIndicator').style.color = '#94a3b8';
    document.getElementById('callStatusIndicator').textContent = '● Connecting…';
    setVideoShellLive(true);
    timerActive = false;

    if (window.McSessionVideoShell && token) {
        const frame = document.getElementById('mcGlobalVideoFrame');
        const alreadySame = frame && frame.src && frame.src.indexOf('token=' + encodeURIComponent(token)) >= 0 && frame.src !== 'about:blank';
        McSessionVideoShell.open(token, consultationId || <?= $consultation_id ?>, {
            mode: 'docked',
            label: 'Live consultation',
            skipReload: alreadySame,
        });
        const dock = document.getElementById('mcProviderVideoDock');
        if (dock) McSessionVideoShell.dock(dock);
        const legacy = document.getElementById('videoFrame');
        if (legacy) {
            legacy.removeAttribute('src');
            legacy.hidden = true;
        }
    } else {
        const legacy = document.getElementById('videoFrame');
        if (legacy) {
            legacy.hidden = false;
            legacy.src = joinUrl;
        }
    }

    showPatientJoinLink(joinUrl);
    return joinUrl;
}

async function startVideoCall() {
    <?php if (!empty($history_view)): ?>
    alert('This consultation has already ended. You can only view its historical record.');
    return;
    <?php endif; ?>
    if (videoCallClosed) {
        alert('This consultation has already ended. You can only view its historical record.');
        return;
    }
    if (currentFinalBucketIsEmergency() && !emergencyVideoIsLive()) {
        alert('This case is an emergency. Send the patient to the nearest hospital instead of starting a new video visit.');
        return;
    }
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
            mcProviderOpenVideo(data.url, <?= $consultation_id ?>);
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
    const legacy = document.getElementById('videoFrame');
    const url = (btn && btn.dataset.videoUrl) || (legacy && legacy.src) || '';
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
    if (existingToken && !videoCallClosed) {
        mcProviderOpenVideo(existingToken, <?= $consultation_id ?>);
    }
});

window.addEventListener('medconnect:video-shell-scroll-away', () => {
    scrollToClinicalSupport();
});

// EXTEND SESSION
async function requestExtension() {
    const msg = document.getElementById('extensionMsg');
    const btn = document.getElementById('extendSessionBtn');
    const frameWin = mcProviderVideoWindow();

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
            if (frameWin) {
                frameWin.postMessage({
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
    const form = document.getElementById('soapForm');
    if (!form) return { success: false };
    syncSoapSignatureFields();
    const fd = new FormData(form);
    fd.append('csrf_token', sessionCsrf || document.body.dataset.csrf || '');
    if (finalize) {
        fd.append('finalize', '1');
        fd.append('soap_confirm', document.getElementById('soapConfirm') && document.getElementById('soapConfirm').checked ? '1' : '0');
    }
    try {
        const res = await fetch('<?= ASSET_BASE ?>/app/api/provider/save_clinical_notes.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!finalize) {
            alert(data.message || (data.success ? 'Draft saved.' : 'Could not save notes.'));
        }
        return data;
    } catch (e) {
        alert('Error saving notes.');
        return { success: false };
    }
}

const soapSignerNames = <?= json_encode([
    'full' => $soap_signer['full_name'] ?? '',
    'candidates' => clinical_note_typed_name_candidates($soap_signer),
], JSON_UNESCAPED_UNICODE) ?>;

let soapPad = null;
let soapUiReady = false;

function soapNormalizeName(value) {
    return String(value || '')
        .replace(/^(dr\.?|dra\.?|doctor)\s+/i, '')
        .replace(/Ã±/gi, 'n')
        .toLowerCase()
        .replace(/[^a-z\s]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function soapTypedNameMatches(value) {
    const typed = soapNormalizeName(value);
    if (!typed) return false;
    const candidates = soapSignerNames.candidates || [];
    if (candidates.indexOf(typed) !== -1) return true;
    return typed === soapNormalizeName(soapSignerNames.full);
}

function soapSelectedMethod() {
    const checked = document.querySelector('#soapForm input[name="signature_method"]:checked');
    return checked ? checked.value : '';
}

function syncSoapSignatureFields() {
    const hidden = document.getElementById('soapSignatureData');
    const method = soapSelectedMethod();
    if (!hidden) return;
    if (method === 'drawn' && soapPad && soapPad.hasInk()) {
        hidden.value = soapPad.toDataURL();
    } else if (method === 'typed') {
        const nameEl = document.getElementById('soapTypedName');
        hidden.value = nameEl ? String(nameEl.value || '').trim() : '';
    } else {
        hidden.value = '';
    }
}

function soapClientValidationMessage() {
    const form = document.getElementById('soapForm');
    if (!form) return 'SOAP form is missing.';
    const required = ['subjective', 'objective', 'assessment', 'plan'];
    for (let i = 0; i < required.length; i++) {
        const field = form.querySelector('[name="' + required[i] + '"]');
        if (!field || !String(field.value || '').trim()) {
            return 'Please complete all SOAP sections (Subjective, Objective, Assessment, and Plan) before finalizing.';
        }
    }
    const method = soapSelectedMethod();
    if (method !== 'typed' && method !== 'drawn') {
        return 'Please provide your electronic signature before finalizing the SOAP note.';
    }
    if (method === 'typed') {
        const nameEl = document.getElementById('soapTypedName');
        const typed = nameEl ? String(nameEl.value || '').trim() : '';
        if (!typed || !soapTypedNameMatches(typed)) {
            return 'The typed name must match your authenticated provider account.';
        }
    } else if (!soapPad || !soapPad.hasInk()) {
        return 'Please provide your electronic signature before finalizing the SOAP note.';
    }
    const confirmEl = document.getElementById('soapConfirm');
    if (!confirmEl || !confirmEl.checked) {
        return 'Please confirm that you reviewed and completed this SOAP note.';
    }
    return '';
}

function updateSoapFinalizeReady() {
    const btn = document.getElementById('soapFinalizeBtn');
    const err = document.getElementById('soapSignError');
    const msg = soapClientValidationMessage();
    if (btn) btn.disabled = msg !== '';
    if (err && msg === '') err.textContent = '';
}

function setSoapMethod(method) {
    const typedPanel = document.getElementById('soapTypedPanel');
    const drawnPanel = document.getElementById('soapDrawnPanel');
    document.querySelectorAll('.soap-sign__method').forEach(function (label) {
        const input = label.querySelector('input[name="signature_method"]');
        label.classList.toggle('is-active', !!(input && input.value === method && input.checked));
    });
    if (typedPanel) typedPanel.hidden = method !== 'typed';
    if (drawnPanel) drawnPanel.hidden = method !== 'drawn';
    if (method === 'drawn' && soapPad) {
        requestAnimationFrame(function () { soapPad.fit(); });
    }
    updateSoapFinalizeReady();
}

function openSoapFinalizeModal() {
    const modal = document.getElementById('soapFinalizeModal');
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeSoapFinalizeModal() {
    const modal = document.getElementById('soapFinalizeModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}

function openSoapSuccessModal(message) {
    const modal = document.getElementById('soapSuccessModal');
    const text = document.getElementById('soapSuccessText');
    if (!modal) return;
    if (text && message) {
        text.textContent = message;
    }
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    const focusBtn = document.getElementById('soapSuccessHistory');
    if (focusBtn && typeof focusBtn.focus === 'function') {
        window.setTimeout(function () { focusBtn.focus(); }, 40);
    }
}

function closeSoapSuccessModal() {
    const modal = document.getElementById('soapSuccessModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}

function initSoapSignatureUi() {
    if (soapUiReady) return;
    soapUiReady = true;
    const canvas = document.getElementById('soapSignatureCanvas');
    if (canvas && window.SoapSignaturePad) {
        soapPad = new window.SoapSignaturePad(canvas, {
            wrap: document.getElementById('soapCanvasWrap'),
            placeholder: document.getElementById('soapCanvasHint'),
        });
        soapPad.onChange = updateSoapFinalizeReady;
    }

    document.querySelectorAll('input[name="signature_method"]').forEach(function (input) {
        input.addEventListener('change', function () {
            setSoapMethod(input.value);
        });
    });

    const nameEl = document.getElementById('soapTypedName');
    if (nameEl) nameEl.addEventListener('input', updateSoapFinalizeReady);

    const confirmEl = document.getElementById('soapConfirm');
    if (confirmEl) confirmEl.addEventListener('change', updateSoapFinalizeReady);

    const form = document.getElementById('soapForm');
    if (form) {
        form.addEventListener('input', updateSoapFinalizeReady);
        form.addEventListener('change', updateSoapFinalizeReady);
        form.addEventListener('reset', function () {
            setTimeout(function () {
                if (soapPad) soapPad.clear();
                setSoapMethod('typed');
                updateSoapFinalizeReady();
            }, 0);
        });
    }

    const clearBtn = document.getElementById('soapClearSignature');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (soapPad) soapPad.clear();
        });
    }

    const finalizeBtn = document.getElementById('soapFinalizeBtn');
    if (finalizeBtn) {
        finalizeBtn.addEventListener('click', function () {
            const msg = soapClientValidationMessage();
            const err = document.getElementById('soapSignError');
            if (msg) {
                if (err) err.textContent = msg;
                return;
            }
            if (err) err.textContent = '';
            openSoapFinalizeModal();
        });
    }

    const cancelBtn = document.getElementById('soapFinalizeCancel');
    if (cancelBtn) cancelBtn.addEventListener('click', closeSoapFinalizeModal);

    const confirmBtn = document.getElementById('soapFinalizeConfirm');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            closeSoapFinalizeModal();
            finalizeConsultation();
        });
    }

    const modal = document.getElementById('soapFinalizeModal');
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeSoapFinalizeModal();
        });
    }

    setSoapMethod(soapSelectedMethod() || 'typed');
    updateSoapFinalizeReady();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSoapSignatureUi);
} else {
    initSoapSignatureUi();
}

// FINALIZE CONSULTATION
async function finalizeConsultation() {
    const err = document.getElementById('soapSignError');
    const msg = soapClientValidationMessage();
    if (msg) {
        if (err) err.textContent = msg;
        return;
    }
    const confirmBtn = document.getElementById('soapFinalizeConfirm');
    const finalizeBtn = document.getElementById('soapFinalizeBtn');
    if (confirmBtn) confirmBtn.disabled = true;
    if (finalizeBtn) finalizeBtn.disabled = true;
    const data = await saveSOAP(true);
    if (confirmBtn) confirmBtn.disabled = false;
    if (data && data.success) {
        openSoapSuccessModal(
            data.message ||
            'SOAP note finalized successfully. The patient can now view this record in My Health.'
        );
        const stayBtn = document.getElementById('soapSuccessStay');
        if (stayBtn && !stayBtn.dataset.bound) {
            stayBtn.dataset.bound = '1';
            stayBtn.addEventListener('click', function () {
                window.location.reload();
            });
        }
        return;
    }
    if (finalizeBtn) finalizeBtn.disabled = false;
    const failMsg = (data && data.message) ? data.message : 'Could not finalize consultation.';
    if (err) {
        err.textContent = failMsg;
    } else {
        alert(failMsg);
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

/* â”€â”€ Post-consultation follow-up decision â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   The provider answers "follow-up required?" once. Choosing Yes offers only
   real openings from their own schedule; when there are none the follow-up is
   still saved as required-but-unscheduled rather than inventing a time. */

let fuFollowUpRequired = null;
let fuSlotsLoaded = false;
let fuSaveInFlight = false;
let fuOpenedFromCallEnd = false;

function fuSetStatus(text, tone) {
    const status = document.getElementById('fuModalStatus');
    if (!status) return;
    status.className = 'fu-status' + (tone ? ' is-' + tone : '');
    status.textContent = text || '';
}

function fuSetDecision(required) {
    fuFollowUpRequired = required;
    const yes = document.getElementById('fuDecisionYes');
    const no = document.getElementById('fuDecisionNo');
    const slotStep = document.getElementById('fuSlotStep');
    const notesStep = document.getElementById('fuNotesStep');
    const saveBtn = document.getElementById('fuSaveBtn');

    if (yes) yes.setAttribute('aria-pressed', required ? 'true' : 'false');
    if (no) no.setAttribute('aria-pressed', required ? 'false' : 'true');
    if (slotStep) slotStep.hidden = !required;
    if (notesStep) notesStep.hidden = !required;
    if (saveBtn) {
        saveBtn.hidden = false;
        saveBtn.textContent = required ? 'Save follow-up' : 'Confirm no follow-up';
    }

    if (required && !fuSlotsLoaded) fuLoadSlots();
    else if (!required) fuSetStatus('');
}

async function fuLoadSlots() {
    const select = document.getElementById('fuSlotSelect');
    const hint = document.getElementById('fuSlotHint');
    if (!select) return;

    try {
        const url = sessionAssetBase + '/app/api/provider/followup_decision.php?consultation_id='
            + encodeURIComponent(sessionConsultationId);
        const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        if (!data || !data.success) {
            select.innerHTML = '<option value="">Could not load your schedule</option>';
            return;
        }

        fuSlotsLoaded = true;

        if (data.already_decided) {
            fuSetStatus('A follow-up decision was already saved for this consultation.', 'ok');
            const saveBtn = document.getElementById('fuSaveBtn');
            if (saveBtn) saveBtn.disabled = true;
            return;
        }

        const slots = Array.isArray(data.slots) ? data.slots : [];
        if (!slots.length) {
            select.innerHTML = '<option value="">No available follow-up slots yet.</option>';
            select.disabled = true;
            if (hint) {
                hint.textContent = 'No future openings in your schedule. Saving will flag this patient as needing a follow-up, and you can book a time once you add availability.';
            }
            return;
        }

        select.disabled = false;
        select.innerHTML = '<option value="">Select an available time…</option>'
            + slots.map((s) => '<option value="' + Number(s.id) + '">'
                + escapeChatHtml(String(s.label || '')) + '</option>').join('');
        if (hint) hint.textContent = 'Only real openings from your own schedule are listed.';
    } catch (e) {
        select.innerHTML = '<option value="">Network error loading slots</option>';
    }
}

function openFollowUpModal(opts) {
    const modal = document.getElementById('followUpModal');
    if (!modal) return;

    fuFollowUpRequired = null;
    fuSlotsLoaded = false;
    fuOpenedFromCallEnd = !!(opts && opts.fromCallEnd);
    const slotStep = document.getElementById('fuSlotStep');
    const notesStep = document.getElementById('fuNotesStep');
    const saveBtn = document.getElementById('fuSaveBtn');
    if (slotStep) slotStep.hidden = true;
    if (notesStep) notesStep.hidden = true;
    if (saveBtn) {
        saveBtn.hidden = true;
        saveBtn.disabled = false;
    }
    ['fuDecisionYes', 'fuDecisionNo'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.setAttribute('aria-pressed', 'false');
    });

    fuSetStatus(opts && opts.fromCallEnd ? 'Consultation completed.' : '', opts && opts.fromCallEnd ? 'ok' : '');

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    const yes = document.getElementById('fuDecisionYes');
    if (yes) yes.focus();
}

function closeFollowUpModal() {
    const modal = document.getElementById('followUpModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');

    // Ending the call is the only path that navigates, and only once the
    // provider has answered or explicitly deferred the follow-up question.
    if (fuOpenedFromCallEnd) {
        fuOpenedFromCallEnd = false;
        window.location.replace(
            <?= json_encode(ASSET_BASE . '/views/provider/consultation_session.php?id=' . (int) $consultation_id . '&soap=1#soapDocumentation') ?>
        );
    }
}

async function saveFollowUpFromModal() {
    if (fuSaveInFlight) return;
    if (fuFollowUpRequired === null) {
        fuSetStatus('Choose whether a follow-up is required.', 'error');
        return;
    }

    const msgEl = document.getElementById('fuFollowUpMessage');
    const select = document.getElementById('fuSlotSelect');
    const saveBtn = document.getElementById('fuSaveBtn');
    const slotId = (fuFollowUpRequired && select && !select.disabled) ? (select.value || '') : '';

    fuSaveInFlight = true;
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
    }
    fuSetStatus('Saving follow-up decision…');

    try {
        const fd = new FormData();
        fd.append('consultation_id', sessionConsultationId);
        fd.append('follow_up_required', fuFollowUpRequired ? '1' : '0');
        if (slotId) fd.append('slot_id', slotId);
        if (msgEl && msgEl.value.trim()) fd.append('notes', msgEl.value.trim());
        fd.append('csrf_token', sessionCsrf);

        const res = await fetch(sessionAssetBase + '/app/api/provider/followup_decision.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });
        const data = await res.json();

        if (!data || !data.success) {
            fuSetStatus((data && data.message) || 'Could not save the follow-up decision.', 'error');
            if (data && /no longer available/i.test(String(data.message || ''))) {
                fuSlotsLoaded = false;
                fuLoadSlots();
            }
            return;
        }

        fuSetStatus(data.message || 'Follow-up decision saved.', 'ok');
        showSessionChatAlert(data.message || 'Follow-up decision saved.', 'success');
        setTimeout(closeFollowUpModal, 1200);
    } catch (e) {
        fuSetStatus('Network error saving the follow-up decision.', 'error');
    } finally {
        fuSaveInFlight = false;
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = fuFollowUpRequired ? 'Save follow-up' : 'Confirm no follow-up';
        }
    }
}

(function bindFollowUpDecision() {
    const yes = document.getElementById('fuDecisionYes');
    const no = document.getElementById('fuDecisionNo');
    if (yes) yes.addEventListener('click', () => fuSetDecision(true));
    if (no) no.addEventListener('click', () => fuSetDecision(false));
})();

(function () {
    var card = document.getElementById('sessionMedicalUpdateCard');
    var form = document.getElementById('sessionMedicalProfileForm');
    if (!card || !form) return;
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var alertEl = document.getElementById('sessionMedicalProfileAlert');
        var fd = new FormData(form);
        fd.append('csrf_token', card.dataset.csrf || '');
        fd.append('patient_id', card.dataset.patientId || '');
        fd.append('request_id', card.dataset.requestId || '');
        try {
            var res = await fetch(sessionAssetBase + '/app/api/provider/update_patient_medical_profile.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            var data = await res.json();
            if (!data.success) throw new Error(data.message || 'Save failed');
            if (alertEl) {
                alertEl.textContent = data.message;
                alertEl.hidden = false;
                alertEl.className = 'hs-profile-alert hs-profile-alert--ok';
            }
            if (typeof showSessionChatAlert === 'function') {
                showSessionChatAlert(data.message || 'Health Summary updated.', 'success');
            }
            setTimeout(function () { window.location.reload(); }, 1200);
        } catch (err) {
            if (alertEl) {
                alertEl.textContent = err.message || 'Could not save profile.';
                alertEl.hidden = false;
                alertEl.className = 'hs-profile-alert hs-profile-alert--err';
            }
        }
    });

    var rejectBtn = document.getElementById('sessionMedicalRejectBtn');
    if (rejectBtn) {
        rejectBtn.addEventListener('click', async function () {
            var note = window.prompt('Optional note to the patient about why this request was rejected:', '');
            if (note === null) return;
            var alertEl = document.getElementById('sessionMedicalProfileAlert');
            rejectBtn.disabled = true;
            try {
                var fd = new FormData();
                fd.append('csrf_token', card.dataset.csrf || '');
                fd.append('patient_id', card.dataset.patientId || '');
                fd.append('request_id', card.dataset.requestId || '');
                fd.append('provider_note', note);
                var res = await fetch(sessionAssetBase + '/app/api/provider/reject_medical_update_request.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                var data = await res.json();
                if (!data.success) throw new Error(data.message || 'Reject failed');
                if (alertEl) {
                    alertEl.textContent = data.message;
                    alertEl.hidden = false;
                    alertEl.className = 'hs-profile-alert hs-profile-alert--ok';
                }
                setTimeout(function () { window.location.reload(); }, 1200);
            } catch (err) {
                if (alertEl) {
                    alertEl.textContent = err.message || 'Could not reject request.';
                    alertEl.hidden = false;
                    alertEl.className = 'hs-profile-alert hs-profile-alert--err';
                }
            } finally {
                rejectBtn.disabled = false;
            }
        });
    }
})();

</script>

<?php require __DIR__.'/partials/layout_close.php'; ?>


