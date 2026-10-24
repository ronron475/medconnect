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
require_once BASE_PATH . '/app/includes/prescription_lines.php';
require_once BASE_PATH . '/app/includes/consultation_video_history.php';
require_once BASE_PATH . '/app/includes/community_bhw_activity.php';
require_once BASE_PATH . '/app/includes/consultation_recorded_data.php';
require_once BASE_PATH . '/app/includes/patient_external_healthcare_visits.php';
require __DIR__ . '/partials/queue_helpers.php';

clinical_tables_ensure($pdo);
patient_consultation_records_schema_ensure($pdo);
clinical_note_signature_schema_ensure($pdo);
consultation_recorded_data_ensure_schema($pdo);
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
$soap_esign_name = trim((string) ($soap_signer['full_name'] ?? ''));
if ($soap_esign_name === '') {
    $soap_esign_name = trim((string) ($soap_signer['legal_name'] ?? ''));
}
$soap_signed_by = $clinical_note
    ? clinical_note_signed_by_label($clinical_note, $soap_signer['display_name'])
    : '';
$soap_signed_at = $clinical_note ? clinical_note_signed_at_label($clinical_note) : '';

$session_referral_facilities = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'facilities'")->rowCount()) {
        $session_referral_facilities = $pdo->query("
            SELECT id, facility_name, facility_type
            FROM facilities
            WHERE status = 'active'
            ORDER BY facility_name
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    $session_referral_facilities = [];
}

$session_access = queue_session_access($c);
$history_view = in_array(strtolower(trim((string) ($c['status'] ?? ''))), ['completed', 'cancelled', 'ended', 'closed'], true);
if (!$session_access['allowed'] && !$history_view) {
    $page_title = 'Session Not Available';
    require __DIR__ . '/partials/layout_open.php';
    ?>
    <div class="mc-card" style="max-width: 640px; margin: 0 auto; padding: 28px 32px;">
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
require_once BASE_PATH . '/app/includes/patient_privacy_access.php';
if (!patient_shares_medical_records_with_providers($pdo, (int) $c['patient_id'])) {
    foreach (['blood_type', 'history', 'allergies', 'medications', 'existing_conditions', 'current_medications'] as $clinicalKey) {
        if (array_key_exists($clinicalKey, $profile)) {
            $profile[$clinicalKey] = '';
        }
    }
    $health_summary['allergies'] = [];
    $health_summary['conditions'] = [];
    $health_summary['medications'] = [];
    $health_summary['blood_type'] = '';
}
$bhw_activity = community_bhw_activity_load_for_provider($pdo, (int) $_SESSION['user_id'], (int) $c['patient_id']);
$bhw_activity_variant = 'provider';
$external_visit_access = patient_external_healthcare_visits_for_authorized_provider(
    $pdo,
    (int) $_SESSION['user_id'],
    (int) $c['patient_id'],
    $consultation_id
);
$external_healthcare_visits = !empty($external_visit_access['allowed'])
    ? ($external_visit_access['visits'] ?? [])
    : [];
$recorded_data = consultation_recorded_data_for_doctor($pdo, $consultation_id, (int) $c['patient_id']);
$recorded_data_history = !empty($recorded_data['available'])
    ? consultation_recorded_data_history($pdo, $consultation_id, (int) $c['patient_id'], 8)
    : [];

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
    $session_messages = message_fetch_pair_messages($pdo, $consultation_id, (int)$_SESSION['user_id']);
    foreach ($session_messages as &$session_message) {
        $session_message['time'] = $session_message['time'] ?? date('M j, Y', strtotime($session_message['created_at'])) . ' • ' . date('g:i A', strtotime($session_message['created_at']));
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
$v_stmt = $pdo->prepare("SELECT room_token, started_at FROM video_sessions WHERE consultation_id = ? AND status = 'active' LIMIT 1");
$v_stmt->execute([$consultation_id]);
$v_session = $v_stmt->fetch();
$room_token = $v_session ? $v_session['room_token'] : '';
$video_elapsed_seconds = 0;
if ($v_session && !empty($v_session['started_at'])) {
    $video_started_ts = strtotime((string) $v_session['started_at']);
    if ($video_started_ts) {
        $video_elapsed_seconds = max(0, time() - $video_started_ts);
    }
}
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
    trim((string) ($c['first_name'] ?? '') . ' ' . (string) ($c['last_name'] ?? '')),
    consultation_scheduled_duration_seconds_for_id($pdo, $consultation_id)
);
$show_video_demo_tip = function_exists('medconnect_is_local_dev_host') && medconnect_is_local_dev_host();
$localhost_app_url = 'http://localhost' . (ASSET_BASE !== '' ? ASSET_BASE : '');
$consultStatusLower = strtolower(trim((string) ($c['status'] ?? '')));
$videoEndedPendingFinal = !$soap_finalized
    && !in_array($consultStatusLower, ['completed', 'cancelled', 'canceled', 'ended', 'closed'], true)
    && (
        !empty($video_history['show_completed_details'])
        || strtolower((string) ($video_history['video_status'] ?? '')) === 'ended'
        || (isset($_GET['soap']) && (string) $_GET['soap'] === '1')
        || (isset($_GET['final_assessment']) && (string) $_GET['final_assessment'] === '1')
    );
?>

<?php $soapSigCssVer = (int) @filemtime(ASSETS_PATH . '/css/soap-signature.css'); ?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/soap-signature.css?v=<?= $soapSigCssVer ?: time() ?>"/>
<?php
$sessionCssVer = (int) @filemtime(ASSETS_PATH . '/css/provider-consultation-session.css');
$patientSuffix = ' · ' . (string) ($patient['name'] ?? '');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/provider-consultation-session.css?v=<?= $sessionCssVer ?: time() ?>"/>
<style id="mc-session-boot-vars">:root{--mc-session-patient-suffix:<?= json_encode($patientSuffix, JSON_UNESCAPED_UNICODE) ?>;}</style>

<?php if (!$consultation_completed): ?>
<div id="finalAssessmentBanner" class="final-assessment-banner<?= !empty($videoEndedPendingFinal) ? ' is-visible' : '' ?>" role="status"<?= empty($videoEndedPendingFinal) ? ' hidden' : '' ?>>
  <div class="final-assessment-banner__copy">
    <p class="final-assessment-banner__title">Final Assessment Required</p>
    <p class="final-assessment-banner__text">
      The video call has ended. This consultation is not completed until you submit the patient’s Final Assessment
      (SOAP note, ICD-10 diagnosis, signature, and final case urgency).
    </p>
  </div>
  <div class="final-assessment-banner__actions">
    <button type="button" class="session-btn primary" id="finalAssessmentGoBtn">Complete Final Assessment</button>
  </div>
</div>
<?php endif; ?>

<div class="session-page consultation-session<?= !empty($history_view) || !empty($video_history['show_completed_details']) ? ' is-post-call' : '' ?>" id="providerSessionPage">
    
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
                <div class="info-row"><span class="info-key">Date</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['date_label'] ?? '—')) ?></span></div>
                <?php if (!empty($video_history['scheduled_duration_label'])): ?>
                <div class="info-row"><span class="info-key">Scheduled duration</span><span class="info-val"><?= htmlspecialchars((string) $video_history['scheduled_duration_label']) ?></span></div>
                <?php endif; ?>
                <div class="info-row"><span class="info-key">Start Time</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['started_label'] ?? '—')) ?></span></div>
                <div class="info-row"><span class="info-key">End Time</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['ended_label'] ?? '—')) ?></span></div>
                <div class="info-row"><span class="info-key">Duration</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['actual_duration_label'] ?: ($video_history['duration_label'] ?? '—'))) ?></span></div>
                <?php if (!empty($video_history['participants_label'])): ?>
                <div class="info-row"><span class="info-key">Participants</span><span class="info-val"><?= htmlspecialchars((string) $video_history['participants_label']) ?></span></div>
                <?php endif; ?>
                <div class="info-row"><span class="info-key">Status</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['status_label'] ?: ($video_history['session_outcome_label'] ?? ($consultation_completed ? 'Completed' : 'Final Assessment required')))) ?></span></div>
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
                <div class="info-row"><span class="info-key">Status</span><span class="info-val"><?= htmlspecialchars((string) ($video_history['video_status_label'] ?? 'Not started')) ?></span></div>
                <?php if (!empty($video_history['started_label'])): ?>
                <div class="info-row"><span class="info-key">Start Time</span><span class="info-val"><?= htmlspecialchars((string) $video_history['started_label']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($video_history['actual_duration_label'])): ?>
                <div class="info-row"><span class="info-key">Duration</span><span class="info-val"><?= htmlspecialchars((string) $video_history['actual_duration_label']) ?></span></div>
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
                <div class="session-card-title"><?= icon('file') ?> Doctor Assessment (SOAP)</div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <?php if ($consultation_completed): ?>
                    <span class="session-btn" style="background:#dcfce7;color:#166534;border:1px solid #86efac;cursor:default;">âœ“ SOAP Finalized</span>
                    <?php else: ?>
                    <button class="session-btn primary" type="button" id="soapSaveDraftBtn" onclick="soapSaveDraft()">Save Draft</button>
                    <button class="session-btn" type="button" onclick="document.getElementById('soapForm').reset()">Clear</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="session-card-body">
                <div class="soap-save-row">
                    <p id="soapSaveStatus" class="soap-save-status<?= $soap_readonly ? ' soap-save-status--final' : '' ?>" role="status" aria-live="polite"><?php if ($soap_readonly): ?>Finalized — this note is read-only.<?php endif; ?></p>
                    <?php if (!$soap_readonly): ?>
                    <button type="button" class="session-btn soap-save-retry" id="soapSaveRetry" hidden>Retry</button>
                    <?php endif; ?>
                </div>
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
                            <label class="pd-label" for="icdSearchInput">Final Diagnosis</label>
                            <?php if ($soap_readonly): ?>
                            <textarea class="pd-textarea" readonly><?= htmlspecialchars((string) ($clinical_note['diagnosis'] ?? '')) ?></textarea>
                            <?php else: ?>
                            <div class="icd-search" id="icdSearch">
                                <input type="search" id="icdSearchInput" class="pd-input icd-search__input" placeholder="Search ICD-10 code or description" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="icdSearchList" aria-autocomplete="list">
                                <ul id="icdSearchList" class="icd-search__list" role="listbox" hidden></ul>
                                <input type="hidden" name="diagnosis" id="icdDiagnosis" value="<?= htmlspecialchars((string) ($clinical_note['diagnosis'] ?? '')) ?>">
                            </div>
                            <p class="soap-sign__hint">Choose a code from the list. Example: A09 – Infectious gastroenteritis and colitis, unspecified</p>
                            <?php endif; ?>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <label class="pd-label">Digital Prescription</label>
                            <?php
                              $soapRxStored = (string) ($clinical_note['prescription'] ?? '');
                              $soapRxDecoded = prescription_lines_decode($soapRxStored);
                              $soapRxRows = $soapRxDecoded['rows'];
                              $soapRxLegacy = prescription_lines_is_encoded($soapRxStored) ? $soapRxDecoded['legacy'] : $soapRxStored;
                            ?>
                            <?php if ($soap_readonly): ?>
                              <?php if ($soapRxRows !== []): ?>
                              <ul class="soap-rx-readonly">
                                <?php foreach ($soapRxRows as $soapRxRow): ?>
                                <li><?= htmlspecialchars($soapRxRow['medication'] . ' · ' . $soapRxRow['dosage'] . ' · ' . $soapRxRow['frequency'] . ' · ' . $soapRxRow['duration']) ?></li>
                                <?php endforeach; ?>
                              </ul>
                              <?php elseif (trim($soapRxLegacy) === ''): ?>
                              <p class="soap-sign__hint">No prescription was recorded on this note.</p>
                              <?php endif; ?>
                              <?php if (trim($soapRxLegacy) !== ''): ?>
                              <div class="soap-rx-legacy"><?= nl2br(htmlspecialchars($soapRxLegacy)) ?></div>
                              <?php endif; ?>
                            <?php else: ?>
                              <?php if ($soapRxRows === []): $soapRxRows = [['medication' => '', 'dosage' => '', 'frequency' => '', 'duration' => '']]; endif; ?>
                              <div id="soapRxRows">
                                <?php foreach ($soapRxRows as $soapRxRow): ?>
                                <div class="soap-rx-row">
                                  <input class="pd-input" name="rx_medication[]" value="<?= htmlspecialchars((string) $soapRxRow['medication']) ?>" placeholder="Medication" aria-label="Medication">
                                  <input class="pd-input" name="rx_dosage[]" value="<?= htmlspecialchars((string) $soapRxRow['dosage']) ?>" placeholder="Dosage" aria-label="Dosage">
                                  <input class="pd-input" name="rx_frequency[]" value="<?= htmlspecialchars((string) $soapRxRow['frequency']) ?>" placeholder="Frequency" aria-label="Frequency">
                                  <input class="pd-input" name="rx_duration[]" value="<?= htmlspecialchars((string) $soapRxRow['duration']) ?>" placeholder="Duration" aria-label="Duration">
                                  <button type="button" class="session-btn" data-rx-remove>Remove</button>
                                </div>
                                <?php endforeach; ?>
                              </div>
                              <div class="soap-rx-actions">
                                <button type="button" class="session-btn" id="soapRxAdd">Add medication</button>
                              </div>
                              <p class="soap-sign__hint">These medications are issued when you finalize. A matching active prescription for this visit is not created again.</p>
                              <?php if (trim($soapRxLegacy) !== ''): ?>
                              <div class="soap-rx-legacy"><?= nl2br(htmlspecialchars($soapRxLegacy)) ?></div>
                              <textarea name="prescription_legacy" hidden><?= htmlspecialchars($soapRxLegacy) ?></textarea>
                              <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$consultation_completed): ?>
                    <div class="soap-sign" id="soapSignature">
                        <h3 class="soap-sign__title">Attestation</h3>
                        <p class="soap-sign__doctor" id="soapProviderName"><?= htmlspecialchars($soap_esign_name !== '' ? $soap_esign_name : 'Provider name unavailable') ?></p>
                        <p class="soap-sign__hint">medConnect generates the SOAP PDF from this form. Preview it, then return here if something needs to change. Finalizing attests the note with your provider account and stores that generated PDF. This is not a cryptographic digital signature.</p>
                        <button type="button" class="session-btn" id="soapPreviewPdfBtn">Preview SOAP PDF</button>

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
                        <?php if (clinical_note_has_signed_pdf($clinical_note)): ?>
                        <a class="session-btn soap-sign__pdf-link" href="<?= htmlspecialchars(clinical_note_signed_pdf_url((int) $consultation_id)) ?>" target="_blank" rel="noopener">View signed PDF</a>
                        <?php elseif (clinical_note_is_image_payload((string) ($clinical_note['signature_data'] ?? ''))): ?>
                        <img class="soap-sign__image" alt="Electronic signature" src="<?= htmlspecialchars((string) $clinical_note['signature_data']) ?>">
                        <?php endif; ?>
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
                    if ($finalUrgency === '' && !empty($clinical_support['manual_urgency'])) {
                        $finalUrgency = match ($riskBucket) {
                            'emergency' => 'EMERGENCY',
                            'urgent' => 'URGENT',
                            'non_urgent', 'routine' => 'NON-URGENT',
                            default => (string) ($clinical_support['risk_level'] ?? 'Not assessed'),
                        };
                    }
                    if ($finalUrgency === '') {
                        $finalUrgency = 'Pending doctor final';
                    }
                    $aiUrgency = (string) ($clinical_support['ai_urgency'] ?? '');
                    if ($aiUrgency === '') {
                        $aiCapsFromRisk = match ($riskBucket) {
                            'emergency' => 'EMERGENCY',
                            'urgent' => 'URGENT',
                            'non_urgent', 'routine' => 'NON-URGENT',
                            default => '',
                        };
                        $aiUrgency = $aiCapsFromRisk !== '' ? $aiCapsFromRisk : 'Not assessed';
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
                            Based on pre-consult triage (confirm final urgency when finalizing SOAP)
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
                        <h4 class="csp-section__title">Adjust urgency during visit</h4>
                        <p class="csp-empty" style="font-style:normal;margin-bottom:8px;">
                            Use this if AI urgency is wrong mid-consultation. The saved override becomes the <strong>Final Doctor Assessment</strong> used when you finalize the SOAP note.
                        </p>
                        <label class="csp-section__title" for="cspManualUrgency">Doctor urgency</label>
                        <select id="cspManualUrgency" class="pd-input">
                            <option value="emergency" <?= $riskBucket === 'emergency' ? 'selected' : '' ?>>Emergency</option>
                            <option value="urgent" <?= $riskBucket === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                            <option value="non_urgent" <?= in_array($riskBucket, ['non_urgent', 'routine'], true) ? 'selected' : '' ?>>Non-Urgent</option>
                        </select>
                        <label class="csp-section__title" for="cspManualNote" style="margin-top:8px;display:block;">Clinical reason (required)</label>
                        <textarea id="cspManualNote" class="pd-textarea" rows="2" placeholder="Why are you adjusting the AI urgency?"><?= htmlspecialchars((string) ($clinical_support['manual_override_note'] ?? '')) ?></textarea>
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

                    <div class="csp-section" id="cspInterviewSection">
                        <h4 class="csp-section__title">Triage interview</h4>
                        <div class="csp-interview" id="cspInterviewTranscript">
                            <?php
                            $interviewTranscript = is_array($clinical_support['interview_transcript'] ?? null)
                                ? $clinical_support['interview_transcript']
                                : [];
                            ?>
                            <?php if ($interviewTranscript === []): ?>
                                <p class="csp-empty">No triage interview answers recorded for this consultation.</p>
                            <?php else: ?>
                                <?php foreach ($interviewTranscript as $turn): ?>
                                    <?php
                                    $interviewQuestion = trim((string) ($turn['question'] ?? ''));
                                    $interviewAnswer = trim((string) ($turn['answer'] ?? ''));
                                    if ($interviewQuestion === '' || $interviewAnswer === '') {
                                        continue;
                                    }
                                    ?>
                                    <div class="csp-interview__item">
                                        <p class="csp-interview__q"><span class="csp-interview__label">Q</span><?= htmlspecialchars($interviewQuestion) ?></p>
                                        <p class="csp-interview__a"><span class="csp-interview__label">A</span><?= htmlspecialchars($interviewAnswer) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
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
                <p class="text-xs text-muted mb-sm">Scheduled slot: <strong id="scheduledEndLabel"><?= htmlspecialchars($slot_end_label) ?></strong>. The visit can continue past this time. The next patient keeps their own slot.</p>
                <div id="readyNextPanel" class="text-xs" style="margin-bottom:10px;"></div>
                <button class="session-btn primary" style="width: 100%; display:none;" type="button" id="readyForNextBtn">
                    Ready for Next Patient Early
                </button>
                <p id="extensionMsg" class="text-xs" style="margin-top: 8px; display: none;"></p>
            </div>
        </div>

        <!-- BHW / PATIENT RECORDED DATA (not doctor assessment) -->
        <div class="session-card prd-card" id="consultationRecordedDataCard"
             data-consultation-id="<?= (int) $consultation_id ?>"
             data-patient-id="<?= (int) $c['patient_id'] ?>">
            <div class="session-card-header">
                <div>
                    <p class="csp-eyebrow" style="margin:0 0 2px;">Pre-consult intake for this visit only</p>
                    <div class="session-card-title"><?= icon('file') ?> BHW/Patient Pre-Consultation Information</div>
                </div>
            </div>
            <div class="session-card-body" id="consultationRecordedDataBody">
                <p class="prd-note">Raw information recorded by the patient or BHW for <strong>this consultation</strong>. It is not the doctor&rsquo;s assessment, diagnosis, or treatment plan. SOAP notes below remain separate.</p>
                <div id="consultationRecordedDataContent">
                <?php if (!empty($recorded_data['available'])): ?>
                <div class="prd-meta">
                    <?php if (!empty($recorded_data['status_label'])): ?>
                    <span>Status: <strong><?= htmlspecialchars((string) $recorded_data['status_label']) ?></strong></span>
                    <?php endif; ?>
                    <span>Added by: <strong><?= htmlspecialchars((string) ($recorded_data['recorded_by_label'] ?: 'Unknown')) ?></strong></span>
                    <?php if (!empty($recorded_data['recorder_role_label'])): ?>
                    <span>Role: <strong><?= htmlspecialchars((string) $recorded_data['recorder_role_label']) ?></strong></span>
                    <?php endif; ?>
                    <?php if (!empty($recorded_data['patient_barangay'])): ?>
                    <span>Barangay: <strong><?= htmlspecialchars((string) $recorded_data['patient_barangay']) ?></strong></span>
                    <?php endif; ?>
                    <?php if (!empty($recorded_data['recorded_at_label'])): ?>
                    <span>Recorded at: <strong><?= htmlspecialchars((string) $recorded_data['recorded_at_label']) ?></strong></span>
                    <?php endif; ?>
                </div>
                <dl class="prd-fields">
                    <?php foreach (($recorded_data['fields'] ?? []) as $field): ?>
                    <div>
                        <dt><?= htmlspecialchars((string) ($field['label'] ?? '')) ?></dt>
                        <dd><?= htmlspecialchars((string) ($field['value'] ?? '')) ?></dd>
                    </div>
                    <?php endforeach; ?>
                </dl>
                <?php if (count($recorded_data_history) > 1): ?>
                <details class="prd-history">
                    <summary>Show earlier recordings for this consultation (<?= (int) count($recorded_data_history) - 1 ?> prior)</summary>
                    <ul class="prd-history-list">
                        <?php foreach (array_slice($recorded_data_history, 1) as $hist): ?>
                        <?php
                            $histFields = consultation_recorded_data_field_list($hist);
                            $histBits = [];
                            foreach ($histFields as $hf) {
                                $histBits[] = $hf['label'] . ': ' . $hf['value'];
                            }
                            $histRole = strtolower((string) ($hist['recorder_role'] ?? 'patient')) === 'bhw'
                                ? 'Barangay Health Worker'
                                : 'Patient';
                            $histName = trim((string) ($hist['recorder_name'] ?? ''));
                            $histWhen = consultation_recorded_data_format_datetime($hist['recorded_at'] ?? null);
                        ?>
                        <li>
                            <strong><?= htmlspecialchars($histName !== '' ? $histName : $histRole) ?></strong>
                            · <?= htmlspecialchars($histRole) ?>
                            · <?= htmlspecialchars($histWhen !== '' ? $histWhen : '—') ?>
                            <?php if ($histBits !== []): ?>
                            <br><?= htmlspecialchars(implode(' · ', $histBits)) ?>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
                <?php endif; ?>
                <?php else: ?>
                <p class="prd-empty">No patient/BHW recorded vitals or complaint snapshot is linked to this consultation yet. When the patient books or the BHW saves vitals for this visit, they will appear here automatically.</p>
                <?php endif; ?>
                </div>
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
                            <button type="submit" class="session-btn success">Approve &amp; Update</button>
                            <button type="button" class="session-btn danger" id="sessionMedicalRejectBtn">Reject</button>
                            <a href="<?= ASSET_BASE ?>/views/provider/medical_records.php?patient_id=<?= (int) $c['patient_id'] ?>" class="session-btn info">Medical Records</a>
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

        <!-- PREVIOUS / EXTERNAL HEALTHCARE INFORMATION (patient history — not this consult) -->
        <div class="session-card exh-card" id="externalHealthcareVisitsCard">
            <div class="session-card-header">
                <div>
                    <p class="csp-eyebrow" style="margin:0 0 2px;">Patient medical history</p>
                    <div class="session-card-title"><?= icon('pin') ?> Previous / External Healthcare Information</div>
                </div>
            </div>
            <div class="session-card-body">
                <p class="prd-note">Visits to hospitals, clinics, or other facilities <strong>outside MedConnect</strong>. This is patient/BHW-reported history — not a MedConnect consultation and not your assessment, diagnosis, or treatment plan.</p>
                <?php if (!empty($external_healthcare_visits)): ?>
                <ul class="exh-list">
                    <?php foreach ($external_healthcare_visits as $ev): ?>
                    <li class="exh-item">
                        <div class="exh-item__kind">External Healthcare Visit · <?= htmlspecialchars((string) ($ev['facility_type_label'] ?? '')) ?></div>
                        <div class="exh-item__name"><?= htmlspecialchars((string) ($ev['facility_name'] ?? '')) ?></div>
                        <div class="exh-item__meta">
                            <?= htmlspecialchars((string) ($ev['visit_date_label'] ?? '')) ?>
                            <?php if (!empty($ev['reason'])): ?>
                            · Reason: <?= htmlspecialchars((string) $ev['reason']) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($ev['reported_diagnosis'])): ?>
                        <div class="exh-item__line"><strong>Reported diagnosis:</strong> <?= htmlspecialchars((string) $ev['reported_diagnosis']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($ev['reported_treatment'])): ?>
                        <div class="exh-item__line"><strong>Reported treatment:</strong> <?= htmlspecialchars((string) $ev['reported_treatment']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($ev['notes'])): ?>
                        <div class="exh-item__line"><strong>Notes:</strong> <?= htmlspecialchars((string) $ev['notes']) ?></div>
                        <?php endif; ?>
                        <div class="exh-item__source">
                            Source: <?= htmlspecialchars((string) ($ev['information_source_label'] ?? 'Patient/BHW Reported')) ?>
                            · Recorded by <?= htmlspecialchars((string) ($ev['recorded_by_label'] ?? 'BHW')) ?>
                            <?php if (!empty($ev['recorded_at_label'])): ?>
                            · <?= htmlspecialchars((string) $ev['recorded_at_label']) ?>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="prd-empty">No external healthcare visits on file for this patient.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- WORKFLOW ACTIONS -->
        <div class="session-card" id="referralFollowupCard">
            <div class="session-card-header"><div class="session-card-title"><?= icon('arrow') ?> Referral & Follow-up</div></div>
            <div class="session-card-body">
                <div class="side-stack">
                    <p class="text-xs text-muted" style="margin:0 0 8px;line-height:1.45;">
                        After assessment you may complete this visit without a referral, or issue a clinical referral when medically appropriate.
                        AI triage does not auto-refer urgent or non-urgent cases — that decision is yours.
                    </p>
                    <label class="pd-label" for="referralType">Referral type / service</label>
                    <select id="referralType" class="pd-input" style="width: 100%;">
                        <option value="">Select referral type…</option>
                        <option value="Hospital">Hospital / Emergency Room</option>
                        <option value="ABTC">ABTC Program</option>
                        <option value="TB-DOTS">TB-DOTS Program</option>
                        <option value="LAB">Laboratory Referral</option>
                        <option value="SPEC">Specialist Referral</option>
                        <option value="Other">Other</option>
                    </select>
                    <label class="pd-label" for="referralFacility" style="margin-top:8px;">Facility / destination</label>
                    <select id="referralFacility" class="pd-input" style="width: 100%;">
                        <option value="">Select facility or destination…</option>
                        <?php foreach ($session_referral_facilities as $fac): ?>
                        <option value="<?= (int) $fac['id'] ?>" data-name="<?= htmlspecialchars((string) $fac['facility_name'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars(trim(($fac['facility_name'] ?? '') . (!empty($fac['facility_type']) ? ' (' . $fac['facility_type'] . ')' : ''))) ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="__other">Other (specify below)</option>
                    </select>
                    <input type="text" id="referralFacilityOther" class="pd-input" style="width:100%;margin-top:6px;display:none;" placeholder="Facility / service destination" maxlength="255" autocomplete="off">
                    <label class="pd-label" for="referralReason" style="margin-top:8px;">Reason for referral</label>
                    <textarea id="referralReason" class="pd-input" rows="3" style="width:100%;resize:vertical;" placeholder="Document why this patient needs in-person care, examination, or specialty services…"></textarea>
                    <label class="pd-label" for="referralNotes" style="margin-top:8px;">Doctor’s notes (optional)</label>
                    <textarea id="referralNotes" class="pd-input" rows="2" style="width:100%;resize:vertical;" placeholder="Additional clinical notes the patient may read with this referral…"></textarea>
                    <button type="button" class="session-btn primary" style="width: 100%; margin-top:8px;" onclick="issueReferral()">Issue Referral</button>
                    <p class="text-xs text-muted" style="margin:6px 0 0;">Issuing a referral does not change consultation completion. Finalize the SOAP note separately when the visit is done.</p>

                    <hr style="border: 0; border-top: 1px solid #e2edf1; margin: 10px 0;">

                    <label class="pd-label">Schedule Follow-up</label>
                    <p class="text-xs text-muted" style="margin:6px 0 0;">Registered mobile: <strong><?= htmlspecialchars($patient_contact !== '' ? $patient_contact : 'Not on file') ?></strong></p>
                    <button type="button" class="session-btn primary" style="width: 100%; margin-top:8px;" onclick="openFollowUpModal()">Book follow-up</button>
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

<div id="finalAssessmentRequiredModal" class="final-assessment-required-modal" aria-hidden="true">
  <div class="final-assessment-required-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="finalAssessmentRequiredTitle" aria-describedby="finalAssessmentRequiredDesc finalAssessmentRequiredCallout">
    <div class="final-assessment-required-modal__body">
      <h2 id="finalAssessmentRequiredTitle" class="final-assessment-required-modal__title">
        <span class="final-assessment-required-modal__badge">Required</span>
        <span class="final-assessment-required-modal__title-text">Final Assessment required</span>
      </h2>
      <p id="finalAssessmentRequiredDesc" class="final-assessment-required-modal__text">
        The video consultation has ended. Submit the patient’s <strong>Final Assessment</strong>
        (SOAP documentation and final case urgency) to complete this visit.
      </p>
      <p id="finalAssessmentRequiredCallout" class="final-assessment-required-modal__callout">
        <strong>AI result is preliminary only.</strong>
        The patient will not see a doctor final assessment until you complete and submit it.
      </p>
    </div>
    <div class="final-assessment-required-modal__footer">
      <button type="button" class="session-btn primary" id="finalAssessmentNowBtn">Complete Final Assessment</button>
    </div>
  </div>
</div>

<?php
$soapSavedDoctorBucket = '';
$soapSavedDoctorReason = '';
$soapSavedOverrideRow = provider_clinical_support_latest_override_row($pdo, (int) $consultation_id);
if ($soapSavedOverrideRow && !empty($clinical_support['manual_urgency'])) {
    $soapSavedBucketCandidate = provider_clinical_support_normalize_bucket((string) ($clinical_support['doctor_urgency_bucket'] ?? ''));
    if (in_array($soapSavedBucketCandidate, ['emergency', 'urgent', 'non_urgent'], true)) {
        $soapSavedDoctorBucket = $soapSavedBucketCandidate;
        $soapSavedDoctorReason = trim((string) ($soapSavedOverrideRow['audit_note'] ?? ''));
    }
}
$soapFinalUrgencyNotePrefill = $soapSavedDoctorBucket !== ''
    ? $soapSavedDoctorReason
    : (string) ($clinical_support['manual_override_note'] ?? '');
?>
<div id="soapFinalizeModal" class="soap-finalize-modal" aria-hidden="true">
  <div class="soap-finalize-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="soapFinalizeTitle">
    <div class="soap-finalize-modal__body">
      <h2 id="soapFinalizeTitle" class="soap-finalize-modal__title">Finalize SOAP Note?</h2>
      <p class="soap-finalize-modal__text">medConnect will generate the SOAP PDF from this form and attest it with your provider account. This is not a cryptographic digital signature. After finalization, the note will become available to the patient and ordinary editing will be disabled.</p>
      <div class="soap-finalize-urgency" style="margin-top:14px;padding-top:12px;border-top:1px solid #e2e8f0;">
        <p class="soap-finalize-modal__text" style="margin-bottom:8px;"><strong>Final Assessment — case urgency</strong> (required)</p>
        <p class="soap-finalize-modal__text" style="margin-bottom:8px;font-size:13px;color:#64748b;">
          Preliminary AI Assessment:
          <strong id="soapFinalizeAiLabel"><?= htmlspecialchars((string) ($clinical_support['ai_urgency'] ?? 'Not assessed')) ?></strong>
          <span style="display:block;margin-top:4px;">Do not use the AI result as your final assessment unless you explicitly confirm it below.</span>
        </p>
        <label for="soapFinalUrgency" style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Final Doctor Assessment</label>
        <select id="soapFinalUrgency" class="pd-input" style="width:100%;" required<?= $soapSavedDoctorBucket !== '' ? ' disabled' : '' ?>>
          <option value="">Select final case urgency…</option>
          <option value="emergency"<?= $soapSavedDoctorBucket === 'emergency' ? ' selected' : '' ?>>Emergency</option>
          <option value="urgent"<?= $soapSavedDoctorBucket === 'urgent' ? ' selected' : '' ?>>Urgent</option>
          <option value="non_urgent"<?= $soapSavedDoctorBucket === 'non_urgent' ? ' selected' : '' ?>>Non-Urgent</option>
        </select>
        <label for="soapFinalUrgencyNote" style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Clinical reason</label>
        <textarea id="soapFinalUrgencyNote" class="pd-textarea" rows="2" style="width:100%;" placeholder="Required when final urgency differs from AI; otherwise confirm why you accept this final level."<?= $soapSavedDoctorBucket !== '' ? ' readonly' : '' ?>><?= htmlspecialchars($soapFinalUrgencyNotePrefill) ?></textarea>
        <p id="soapFinalUrgencyHint" class="soap-finalize-modal__text" style="margin:8px 0 0;font-size:12px;color:#64748b;">You must select and submit a Final Assessment before this consultation is marked completed.</p>
      </div>
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
      <h2 id="soapSuccessTitle" class="soap-finalize-modal__title">Final Assessment submitted</h2>
      <p id="soapSuccessText" class="soap-finalize-modal__text">This consultation is now completed. The patient can view the Final Assessment in My Health / My Sessions.</p>
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
<script src="<?= ASSET_BASE ?>/assets/js/soap-draft-autosave.js?v=1"></script>
<script>
// SESSION TIMER
let seconds = <?= (int) $video_elapsed_seconds ?>;
let sessionTimerAnchorMs = seconds > 0 ? Date.now() - seconds * 1000 : null;
let timerActive = false;
let mobileCallFullscreen = false;
let desktopVideoExpanded = false;
let trueCallFullscreen = false;
let trueFullscreenOpenedMobileLayer = false;
let videoCallClosed = <?= (!empty($history_view) || !empty($videoEndedPendingFinal)) ? 'true' : 'false' ?>;
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
    trueFullscreenOpenedMobileLayer = false;
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
    trueFullscreenOpenedMobileLayer = false;
    if (!mobileCallFullscreen) {
        enterMobileCallFullscreen();
        // Mobile keeps this layer for the whole call; only desktop must undo it on exit.
        trueFullscreenOpenedMobileLayer = mobileCallFullscreen && !isMobileConsultation();
    }
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
    if (trueFullscreenOpenedMobileLayer) {
        trueFullscreenOpenedMobileLayer = false;
        if (!isMobileConsultation()) exitMobileCallFullscreen();
    }
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
    if (prompt) prompt.textContent = 'The video call has ended. Submit the Final Assessment (SOAP + final case urgency) below. This consultation is not completed until you submit it.';
    if (startBtn) startBtn.hidden = true;
    const help = document.getElementById('videoPreCallHelp');
    if (help) help.hidden = true;
    document.body.classList.add('consultation-call-ended');
    const sessionPage = document.getElementById('providerSessionPage') || document.querySelector('.session-page');
    if (sessionPage) sessionPage.classList.add('is-post-call');
    showFinalAssessmentBanner();
}

function showFinalAssessmentBanner() {
    const banner = document.getElementById('finalAssessmentBanner');
    if (!banner) return;
    banner.hidden = false;
    banner.classList.add('is-visible');
}

function scrollToFinalAssessment() {
    const soapCard = document.getElementById('soapDocumentation');
    if (soapCard) {
        soapCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        soapCard.classList.add('is-highlight-final');
        window.setTimeout(function () { soapCard.classList.remove('is-highlight-final'); }, 2400);
    }
    const finalizeBtn = document.getElementById('soapFinalizeBtn');
    if (finalizeBtn && typeof finalizeBtn.focus === 'function') {
        window.setTimeout(function () { finalizeBtn.focus(); }, 280);
    }
}

function openFinalAssessmentRequiredModal() {
    const modal = document.getElementById('finalAssessmentRequiredModal');
    if (!modal) {
        scrollToFinalAssessment();
        return;
    }
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('final-assessment-modal-open');
    const nowBtn = document.getElementById('finalAssessmentNowBtn');
    if (nowBtn && typeof nowBtn.focus === 'function') {
        window.setTimeout(function () { nowBtn.focus(); }, 40);
    }
}

function closeFinalAssessmentRequiredModal() {
    const modal = document.getElementById('finalAssessmentRequiredModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('final-assessment-modal-open');
}

function promptFinalAssessmentRequired() {
    showFinalAssessmentBanner();
    openFinalAssessmentRequiredModal();
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
let sessionRecordedDataTimer = null;
let sessionRecordedDataInFlight = false;
let sessionRecordedDataFingerprint = '';

function escapePrdHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function renderConsultationRecordedData(payload) {
    const mount = document.getElementById('consultationRecordedDataContent');
    if (!mount || !payload) return;
    const recorded = payload.recorded || {};
    const history = Array.isArray(payload.history) ? payload.history : [];
    if (!recorded.available) {
        mount.innerHTML = '<p class="prd-empty">No patient/BHW recorded vitals or complaint snapshot is linked to this consultation yet. When the patient books or the BHW saves vitals for this visit, they will appear here automatically.</p>';
        return;
    }
    const fields = Array.isArray(recorded.fields) ? recorded.fields : [];
    let html = '<div class="prd-meta">';
    if (recorded.status_label) {
        html += '<span>Status: <strong>' + escapePrdHtml(recorded.status_label) + '</strong></span>';
    }
    html += '<span>Added by: <strong>' + escapePrdHtml(recorded.recorded_by_label || 'Unknown') + '</strong></span>';
    if (recorded.recorder_role_label) {
        html += '<span>Role: <strong>' + escapePrdHtml(recorded.recorder_role_label) + '</strong></span>';
    }
    if (recorded.patient_barangay) {
        html += '<span>Barangay: <strong>' + escapePrdHtml(recorded.patient_barangay) + '</strong></span>';
    }
    if (recorded.recorded_at_label) {
        html += '<span>Recorded at: <strong>' + escapePrdHtml(recorded.recorded_at_label) + '</strong></span>';
    }
    html += '</div><dl class="prd-fields">';
    fields.forEach(function (field) {
        html += '<div><dt>' + escapePrdHtml(field.label || '') + '</dt><dd>' + escapePrdHtml(field.value || '') + '</dd></div>';
    });
    html += '</dl>';
    if (history.length > 1) {
        html += '<details class="prd-history"><summary>Show earlier recordings for this consultation (' + (history.length - 1) + ' prior)</summary><ul class="prd-history-list">';
        history.slice(1).forEach(function (hist) {
            const role = String(hist.recorder_role || 'patient').toLowerCase() === 'bhw'
                ? 'Barangay Health Worker'
                : 'Patient';
            const histName = String(hist.recorder_name || '').trim();
            const bits = [];
            if (hist.chief_complaint) bits.push('Chief Complaint: ' + hist.chief_complaint);
            if (hist.symptoms) bits.push('Symptoms: ' + hist.symptoms);
            if (hist.temperature_c != null && hist.temperature_c !== '') bits.push('Temperature: ' + hist.temperature_c + '°C');
            if (hist.blood_pressure) bits.push('Blood Pressure: ' + hist.blood_pressure);
            if (hist.pulse_bpm != null && hist.pulse_bpm !== '') bits.push('Pulse Rate: ' + hist.pulse_bpm + ' bpm');
            if (hist.notes) bits.push('Other observations: ' + hist.notes);
            html += '<li><strong>' + escapePrdHtml(histName || role) + '</strong> · ' + escapePrdHtml(role) + ' · ' + escapePrdHtml(hist.recorded_at || '—');
            if (bits.length) html += '<br>' + escapePrdHtml(bits.join(' · '));
            html += '</li>';
        });
        html += '</ul></details>';
    }
    mount.innerHTML = html;
}

async function refreshConsultationRecordedData() {
    if (sessionRecordedDataInFlight || !sessionConsultationId) return;
    sessionRecordedDataInFlight = true;
    try {
        const url = sessionAssetBase + '/app/api/provider/recorded_data.php?consultation_id='
            + encodeURIComponent(sessionConsultationId)
            + '&patient_id=' + encodeURIComponent(sessionPatientId)
            + '&_=' + Date.now();
        const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-MC-No-Loader': '1' } });
        const data = await res.json();
        if (!data || !data.success) return;
        const fp = JSON.stringify({
            recorded: data.recorded || null,
            history_ids: (data.history || []).map(function (h) { return h.id || h.recorded_at; })
        });
        if (fp === sessionRecordedDataFingerprint) return;
        sessionRecordedDataFingerprint = fp;
        renderConsultationRecordedData(data);
    } catch (e) {
        /* keep last rendered panel */
    } finally {
        sessionRecordedDataInFlight = false;
    }
}

if (document.getElementById('consultationRecordedDataCard')) {
    refreshConsultationRecordedData();
    sessionRecordedDataTimer = setInterval(refreshConsultationRecordedData, 8000);
}

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
    sessionRealtimePoller.start(5000);
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
    sessionChatRefreshTimer = setInterval(refreshSessionChat, 5000);
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
    if (params.get('soap') === '1' || params.get('final_assessment') === '1' || window.location.hash === '#soapDocumentation') {
        const soapCard = document.getElementById('soapDocumentation');
        if (soapCard) {
            window.setTimeout(() => soapCard.scrollIntoView({ behavior: 'smooth', block: 'start' }), 200);
        }
        showFinalAssessmentBanner();
        if (params.get('final_assessment') === '1') {
            window.setTimeout(openFinalAssessmentRequiredModal, 350);
        }
        params.delete('soap');
        params.delete('final_assessment');
        const next = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + (window.location.hash || '#soapDocumentation');
        window.history.replaceState({}, '', next);
    } else if (params.get('followup') === '1') {
        openFollowUpModal({ fromCallEnd: true });
        params.delete('followup');
        const next = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
        window.history.replaceState({}, '', next);
    } else if (videoCallClosed && !<?= $consultation_completed ? 'true' : 'false' ?>) {
        showFinalAssessmentBanner();
        const title = document.querySelector('.video-pre-call__title');
        const prompt = document.querySelector('.video-pre-call__prompt');
        const startBtn = document.querySelector('.video-pre-call__start');
        if (title && <?= !empty($videoEndedPendingFinal) ? 'true' : 'false' ?>) title.textContent = 'Consultation ended';
        if (prompt && <?= !empty($videoEndedPendingFinal) ? 'true' : 'false' ?>) {
            prompt.textContent = 'The video call has ended. Submit the Final Assessment (SOAP + final case urgency) below. This consultation is not completed until you submit it.';
        }
        if (startBtn && <?= !empty($videoEndedPendingFinal) ? 'true' : 'false' ?>) startBtn.hidden = true;
    }

    const goFinalBtn = document.getElementById('finalAssessmentGoBtn');
    if (goFinalBtn) {
        goFinalBtn.addEventListener('click', function () {
            closeFinalAssessmentRequiredModal();
            scrollToFinalAssessment();
        });
    }
    const nowFinalBtn = document.getElementById('finalAssessmentNowBtn');
    if (nowFinalBtn) {
        nowFinalBtn.addEventListener('click', function () {
            closeFinalAssessmentRequiredModal();
            scrollToFinalAssessment();
        });
    }
    const finalModal = document.getElementById('finalAssessmentRequiredModal');
    if (finalModal) {
        // Required gate: do not dismiss via backdrop click or Escape.
        finalModal.addEventListener('click', function (e) {
            if (e.target === finalModal) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (!finalModal.classList.contains('is-open')) return;
            e.preventDefault();
            e.stopPropagation();
        });
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
    const finalUrgency = support.manual_urgency
        ? (support.final_urgency || support.doctor_urgency || 'Not assessed')
        : (support.final_urgency || 'Pending doctor final');
    const aiUrgency = support.ai_urgency
        || (String(support.risk_level || '').replace(/\s*\(preliminary AI\)\s*$/i, '').trim())
        || 'Not assessed';
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

    renderCspInterview(support.interview_transcript || []);

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

function renderCspInterview(transcript) {
    const box = document.getElementById('cspInterviewTranscript');
    if (!box) return;
    const rows = Array.isArray(transcript) ? transcript : [];
    const visible = rows.filter(function (row) {
        return row && String(row.question || '').trim() && String(row.answer || '').trim();
    });
    if (!visible.length) {
        box.innerHTML = '<p class="csp-empty">No triage interview answers recorded for this consultation.</p>';
        return;
    }
    box.innerHTML = visible.map(function (row) {
        return '<div class="csp-interview__item">' +
            '<p class="csp-interview__q"><span class="csp-interview__label">Q</span>' + escapeHtml(row.question) + '</p>' +
            '<p class="csp-interview__a"><span class="csp-interview__label">A</span>' + escapeHtml(row.answer) + '</p>' +
            '</div>';
    }).join('');
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
        if (['emergency', 'urgent', 'non_urgent'].indexOf(String(persisted.final_bucket || '')) !== -1) {
            soapSavedDoctorUrgency = {
                bucket: String(persisted.final_bucket),
                reason: String(persisted.clinical_reason || note),
            };
            syncSoapFinalizeSavedUrgency();
            syncSoapFinalizeUrgencyHint();
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
    if (sessionTimerAnchorMs === null) sessionTimerAnchorMs = Date.now() - seconds * 1000;
    seconds = Math.max(0, Math.floor((Date.now() - sessionTimerAnchorMs) / 1000));
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
        document.body.classList.add('consultation-call-ended');
        document.body.classList.remove('consultation-mobile-call-fullscreen', 'consultation-true-fullscreen', 'consultation-desktop-video-expanded');
        const sessionPage = document.getElementById('providerSessionPage') || document.querySelector('.session-page');
        if (sessionPage) sessionPage.classList.add('is-post-call');
        // Video ended only — consultation stays open until Final Assessment is submitted.
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
        const res = await fetch('<?= ASSET_BASE ?>/app/api/consultations/open_scheduled_video.php', {
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

// Queue timing: early next patient, without ending the booked slot.
(function pollReadyForNext() {
    const panel = document.getElementById('readyNextPanel');
    const btn = document.getElementById('readyForNextBtn');
    if (!panel || !btn) return;

    async function paint() {
        try {
            const res = await fetch('<?= ASSET_BASE ?>/app/api/provider/queue_status.php?_=' + Date.now(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-MC-No-Loader': '1' },
                cache: 'no-store'
            });
            const data = await res.json();
            if (!data || !data.success) return;
            const next = data.next_patient;
            const activeVideoId = parseInt(data.active_video_consultation_id || 0, 10);
            const name = (next && next.patient_name) || 'The next patient';
            const when = next && next.scheduled_label ? ' at ' + next.scheduled_label : '';
            if (!next) {
                panel.textContent = '';
                btn.style.display = 'none';
                return;
            }
            if (activeVideoId) {
                panel.textContent = 'Previous consultation in progress. Next patient is waiting: ' + name + when + '.';
                btn.style.display = 'none';
            } else if (next.early_start_response === 'join_early') {
                panel.textContent = name + ' chose Start Early. Scheduled time' + when + ' is unchanged.';
                btn.style.display = 'none';
            } else if (next.early_start_response === 'keep_time') {
                panel.textContent = name + ' chose Keep Scheduled Time. This is not a missed visit.';
                btn.style.display = 'none';
            } else if (next.can_offer_early) {
                panel.textContent = 'Ready for Next Patient Early. ' + name + ' is scheduled' + when + '.';
                btn.style.display = 'block';
            } else if (next.early_start_response_label) {
                panel.textContent = name + ': ' + next.early_start_response_label + '.';
                btn.style.display = 'none';
            } else {
                panel.textContent = '';
                btn.style.display = 'none';
            }
        } catch (_) {}
    }

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        const body = new URLSearchParams({
            csrf_token: document.body.dataset.csrf || ''
        });
        try {
            const res = await fetch('<?= ASSET_BASE ?>/app/api/provider/ready_for_next.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body
            });
            const data = await res.json();
            const msg = document.getElementById('extensionMsg');
            if (msg) {
                msg.style.display = 'block';
                msg.textContent = (data && data.message) || '';
            }
        } catch (_) {}
        btn.disabled = false;
        paint();
    });

    paint();
    setInterval(paint, 5000);
    document.addEventListener('medconnect:notifications-arrived', paint);
})();

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
function soapDraftSnapshot() {
    const form = document.getElementById('soapForm');
    const names = ['subjective', 'objective', 'assessment', 'plan', 'diagnosis', 'prescription_legacy'];
    const base = names.map(function (name) {
        const field = form ? form.querySelector('[name="' + name + '"]') : null;
        return name + '\n' + (field ? String(field.value || '') : '');
    }).join('\n--\n');
    const lines = [];
    if (form) {
        form.querySelectorAll('.soap-rx-row').forEach(function (row) {
            lines.push(['rx_medication[]', 'rx_dosage[]', 'rx_frequency[]', 'rx_duration[]'].map(function (name) {
                const field = row.querySelector('[name="' + name + '"]');
                return field ? String(field.value || '') : '';
            }).join('\t'));
        });
    }
    return base + '\n--\n' + lines.join('\n');
}

function soapSavedStamp(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!match) return '';
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]));
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function soapRenderSaveStatus(state, savedAt) {
    const el = document.getElementById('soapSaveStatus');
    const retry = document.getElementById('soapSaveRetry');
    if (!el) return;
    el.className = 'soap-save-status';
    if (state === 'unsaved') {
        el.textContent = 'Unsaved changes';
    } else if (state === 'saving') {
        el.classList.add('soap-save-status--saving');
        el.textContent = 'Saving...';
    } else if (state === 'saved') {
        el.classList.add('soap-save-status--saved');
        const stamp = soapSavedStamp(savedAt);
        el.textContent = stamp ? ('Draft saved · ' + stamp) : 'Draft saved';
    } else if (state === 'failed') {
        el.classList.add('soap-save-status--failed');
        el.textContent = 'Save failed — retry';
    } else if (state === 'finalized') {
        el.classList.add('soap-save-status--final');
        el.textContent = 'Finalized — this note is read-only.';
        soapLockDraftForm();
    }
    if (retry) retry.hidden = state !== 'failed';
}

function soapLockDraftForm() {
    const form = document.getElementById('soapForm');
    if (form) {
        form.querySelectorAll('textarea, input').forEach(function (field) {
            if (field.type === 'hidden') return;
            field.readOnly = true;
        });
    }
    ['soapSaveDraftBtn', 'soapPreviewPdfBtn', 'soapFinalizeBtn', 'soapRxAdd'].forEach(function (id) {
        const button = document.getElementById(id);
        if (button) button.disabled = true;
    });
    if (form) {
        form.querySelectorAll('[data-rx-remove]').forEach(function (button) {
            button.disabled = true;
        });
    }
}

function soapDraftNoteEdited() {
    if (window.soapDraftSaver) window.soapDraftSaver.noteEdited();
}

function soapSaveDraft() {
    if (window.soapDraftSaver) return window.soapDraftSaver.save(true);
    return postClinicalNotes(false);
}

function initSoapDraftAutosave() {
    if (window.soapDraftSaver || !window.MedconnectSoapDraft || !document.getElementById('soapForm')) return;
    window.soapDraftSaver = MedconnectSoapDraft.createSoapDraftSaver({
        delayMs: 2000,
        finalized: <?= $soap_readonly ? 'true' : 'false' ?>,
        initialSnapshot: soapDraftSnapshot(),
        current: soapDraftSnapshot,
        setTimer: function (fn, ms) { return window.setTimeout(fn, ms); },
        clearTimer: function (id) { window.clearTimeout(id); },
        post: function () { return postClinicalNotes(false); },
        onStatus: soapRenderSaveStatus,
    });
    const retry = document.getElementById('soapSaveRetry');
    if (retry) {
        retry.addEventListener('click', function () {
            window.soapDraftSaver.save(true);
        });
    }
    window.addEventListener('beforeunload', function (event) {
        if (!window.soapDraftSaver || !window.soapDraftSaver.shouldWarn()) return;
        event.preventDefault();
        event.returnValue = '';
    });
}

async function postClinicalNotes(finalize) {
    const form = document.getElementById('soapForm');
    if (!form) return { success: false };
    syncSoapSignatureFields();
    const fd = new FormData(form);
    fd.delete('signed_pdf');
    fd.set('csrf_token', sessionCsrf || document.body.dataset.csrf || '');
    if (!finalize) {
        fd.delete('finalize');
        fd.delete('soap_confirm');
    } else {
        fd.set('finalize', '1');
        fd.set('soap_confirm', document.getElementById('soapConfirm') && document.getElementById('soapConfirm').checked ? '1' : '0');
        if (soapHasSavedDoctorUrgency()) {
            fd.set('final_urgency_bucket', soapSavedDoctorUrgency.bucket);
            fd.set('final_urgency_note', soapSavedDoctorUrgency.reason);
        } else {
            const urgencyEl = document.getElementById('soapFinalUrgency');
            const noteEl = document.getElementById('soapFinalUrgencyNote');
            fd.set('final_urgency_bucket', urgencyEl ? String(urgencyEl.value || '') : '');
            fd.set('final_urgency_note', noteEl ? String(noteEl.value || '').trim() : '');
        }
    }
    try {
        const res = await fetch('<?= ASSET_BASE ?>/app/api/provider/save_clinical_notes.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-MC-No-Loader': '1' },
        });
        const data = await res.json();
        return data && typeof data === 'object' ? data : { success: false };
    } catch (e) {
        return { success: false };
    }
}

async function saveSOAP(finalize = false) {
    if (!finalize) return soapSaveDraft();
    return postClinicalNotes(true);
}

const soapSignerNames = <?= json_encode([
    'full' => $soap_esign_name,
], JSON_UNESCAPED_UNICODE) ?>;

let soapSavedDoctorUrgency = <?= json_encode([
    'bucket' => $soapSavedDoctorBucket,
    'reason' => $soapSavedDoctorReason,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

let soapUiReady = false;
let soapFinalizeBusy = false;

function syncSoapSignatureFields() {
    const name = String(soapSignerNames.full || '').trim();
    const display = document.getElementById('soapProviderName');
    if (display && display.tagName !== 'INPUT') display.textContent = name || 'Provider name unavailable';
}

async function previewSoapPdf() {
    const form = document.getElementById('soapForm');
    const err = document.getElementById('soapSignError');
    const btn = document.getElementById('soapPreviewPdfBtn');
    if (!form) return;
    if (btn) btn.disabled = true;
    if (err) err.textContent = '';
    try {
        const fd = new FormData(form);
        fd.delete('signed_pdf');
        fd.delete('finalize');
        fd.append('csrf_token', sessionCsrf || document.body.dataset.csrf || '');
        const res = await fetch('<?= ASSET_BASE ?>/app/api/provider/preview_soap_pdf.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });
        const type = String(res.headers.get('Content-Type') || '');
        if (!res.ok || type.indexOf('application/pdf') === -1) {
            let msg = 'Could not preview the SOAP PDF.';
            try {
                const data = await res.json();
                if (data && data.message) msg = data.message;
            } catch (e) {}
            if (err) err.textContent = msg;
            return;
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const opened = window.open(url, '_blank', 'noopener');
        if (!opened && err) {
            err.textContent = 'Allow pop-ups to preview the SOAP PDF.';
        }
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
    } catch (e) {
        if (err) err.textContent = 'Could not preview the SOAP PDF.';
    } finally {
        if (btn) btn.disabled = false;
    }
}

function soapClientValidationMessage() {
    const form = document.getElementById('soapForm');
    if (!form) return 'SOAP form is missing.';
    const required = [
        ['subjective', 'Subjective is required.'],
        ['objective', 'Objective is required.'],
        ['assessment', 'Assessment is required.'],
        ['plan', 'Plan is required.']
    ];
    for (let i = 0; i < required.length; i++) {
        const field = form.querySelector('[name="' + required[i][0] + '"]');
        if (!field || !String(field.value || '').trim()) {
            return required[i][1];
        }
    }
    const diagnosis = document.getElementById('icdDiagnosis');
    if (!diagnosis || !String(diagnosis.value || '').trim()) {
        return 'Select a final diagnosis from the ICD-10 list.';
    }
    const rxRows = form.querySelectorAll('.soap-rx-row');
    for (let r = 0; r < rxRows.length; r++) {
        const parts = ['rx_medication[]', 'rx_dosage[]', 'rx_frequency[]', 'rx_duration[]'].map(function (name) {
            const field = rxRows[r].querySelector('[name="' + name + '"]');
            return field ? String(field.value || '').trim() : '';
        });
        const filled = parts.filter(function (part) { return part !== ''; }).length;
        if (filled > 0 && filled < 4) {
            return 'Each medication needs a dosage, frequency, and duration.';
        }
    }
    syncSoapSignatureFields();
    if (!String(soapSignerNames.full || '').trim()) {
        return 'Provider identity could not be verified. Please refresh and try again.';
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
    if (soapFinalizeBusy) {
        if (btn) btn.disabled = true;
        return;
    }
    const msg = soapClientValidationMessage();
    if (btn) btn.disabled = msg !== '';
    if (err) err.textContent = msg;
}

function openSoapFinalizeModal() {
    const modal = document.getElementById('soapFinalizeModal');
    if (!modal) return;
    syncSoapFinalizeSavedUrgency();
    syncSoapFinalizeUrgencyHint();
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeSoapFinalizeModal() {
    const modal = document.getElementById('soapFinalizeModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}

function soapFinalizeAiBucket() {
    const label = String((document.getElementById('soapFinalizeAiLabel') || {}).textContent || '').toLowerCase();
    if (label.indexOf('emergency') !== -1) return 'emergency';
    if (label.indexOf('urgent') !== -1 && label.indexOf('non') === -1) return 'urgent';
    if (label.indexOf('non') !== -1) return 'non_urgent';
    return '';
}

function soapHasSavedDoctorUrgency() {
    return !!soapSavedDoctorUrgency
        && ['emergency', 'urgent', 'non_urgent'].indexOf(String(soapSavedDoctorUrgency.bucket || '')) !== -1;
}

function syncSoapFinalizeSavedUrgency() {
    const urgencyEl = document.getElementById('soapFinalUrgency');
    const noteEl = document.getElementById('soapFinalUrgencyNote');
    if (!soapHasSavedDoctorUrgency()) {
        if (urgencyEl) urgencyEl.disabled = false;
        if (noteEl) noteEl.readOnly = false;
        return;
    }
    if (urgencyEl) {
        urgencyEl.value = soapSavedDoctorUrgency.bucket;
        urgencyEl.disabled = true;
    }
    if (noteEl) {
        noteEl.value = String(soapSavedDoctorUrgency.reason || '');
        noteEl.readOnly = true;
    }
}

function soapFinalizeUrgencyValidationMessage() {
    if (soapHasSavedDoctorUrgency()) return '';
    const urgencyEl = document.getElementById('soapFinalUrgency');
    const noteEl = document.getElementById('soapFinalUrgencyNote');
    const bucket = urgencyEl ? String(urgencyEl.value || '').trim() : '';
    if (['emergency', 'urgent', 'non_urgent'].indexOf(bucket) === -1) {
        return 'Final Assessment required: select Emergency, Urgent, or Non-Urgent before completing this consultation.';
    }
    const note = noteEl ? String(noteEl.value || '').trim() : '';
    const aiBucket = soapFinalizeAiBucket();
    if (aiBucket && bucket !== aiBucket && note.length < 3) {
        return 'Add a brief clinical reason when final urgency differs from the AI preliminary result.';
    }
    return '';
}

function syncSoapFinalizeUrgencyHint() {
    const hint = document.getElementById('soapFinalUrgencyHint');
    const urgencyEl = document.getElementById('soapFinalUrgency');
    if (!hint || !urgencyEl) return;
    if (soapHasSavedDoctorUrgency()) {
        hint.textContent = 'Using your saved Doctor Urgency Override as the Final Doctor Assessment. To change it, use "Adjust urgency during visit" and save a new override.';
        return;
    }
    const bucket = String(urgencyEl.value || '');
    const aiBucket = soapFinalizeAiBucket();
    if (aiBucket && bucket !== aiBucket) {
        hint.textContent = 'Final urgency differs from AI — a clinical reason is required.';
    } else {
        hint.textContent = 'Confirming this urgency as the authoritative final result for this visit.';
    }
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
    initSoapDraftAutosave();

    const previewBtn = document.getElementById('soapPreviewPdfBtn');
    if (previewBtn) {
        previewBtn.addEventListener('click', function () {
            previewSoapPdf();
        });
    }
    initIcdSearch();
    syncSoapSignatureFields();

    const confirmEl = document.getElementById('soapConfirm');
    if (confirmEl) confirmEl.addEventListener('change', updateSoapFinalizeReady);

    const form = document.getElementById('soapForm');
    const rxRows = document.getElementById('soapRxRows');
    const rxAdd = document.getElementById('soapRxAdd');
    if (rxRows && rxAdd) {
        rxAdd.addEventListener('click', function () {
            const row = rxRows.querySelector('.soap-rx-row');
            if (!row) return;
            const copy = row.cloneNode(true);
            copy.querySelectorAll('input').forEach(function (input) { input.value = ''; });
            rxRows.appendChild(copy);
            soapDraftNoteEdited();
        });
        rxRows.addEventListener('click', function (event) {
            const button = event.target.closest('[data-rx-remove]');
            if (!button) return;
            const rows = rxRows.querySelectorAll('.soap-rx-row');
            if (rows.length <= 1) {
                rows[0].querySelectorAll('input').forEach(function (input) { input.value = ''; });
            } else {
                const row = button.closest('.soap-rx-row');
                if (row) row.remove();
            }
            soapDraftNoteEdited();
        });
    }
    if (form) {
        form.addEventListener('input', function () {
            updateSoapFinalizeReady();
            soapDraftNoteEdited();
        });
        form.addEventListener('change', function () {
            updateSoapFinalizeReady();
            soapDraftNoteEdited();
        });
        form.addEventListener('reset', function () {
            setTimeout(function () {
                const diagnosis = document.getElementById('icdDiagnosis');
                const search = document.getElementById('icdSearchInput');
                if (diagnosis) diagnosis.value = '';
                if (search) search.value = '';
                syncSoapSignatureFields();
                updateSoapFinalizeReady();
                soapDraftNoteEdited();
            }, 0);
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
            const urgencyMsg = soapFinalizeUrgencyValidationMessage();
            if (urgencyMsg) {
                const hint = document.getElementById('soapFinalUrgencyHint');
                if (hint) hint.textContent = urgencyMsg;
                return;
            }
            closeSoapFinalizeModal();
            finalizeConsultation();
        });
    }

    const urgencySelect = document.getElementById('soapFinalUrgency');
    if (urgencySelect) {
        urgencySelect.addEventListener('change', syncSoapFinalizeUrgencyHint);
    }
    const urgencyNote = document.getElementById('soapFinalUrgencyNote');
    if (urgencyNote) {
        urgencyNote.addEventListener('input', syncSoapFinalizeUrgencyHint);
    }

    const modal = document.getElementById('soapFinalizeModal');
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeSoapFinalizeModal();
        });
    }

    updateSoapFinalizeReady();
}

function initIcdSearch() {
    const input = document.getElementById('icdSearchInput');
    const list = document.getElementById('icdSearchList');
    const hidden = document.getElementById('icdDiagnosis');
    const wrap = document.getElementById('icdSearch');
    if (!input || !list || !hidden) return;
    let timer = 0;
    let requestSeq = 0;
    if (hidden.value) input.value = hidden.value;

    function setOpen(open) {
        list.hidden = !open;
        input.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (wrap) wrap.classList.toggle('is-open', open);
    }

    function closeList() {
        setOpen(false);
    }

    function showStatus(text) {
        list.innerHTML = '';
        const li = document.createElement('li');
        li.className = 'icd-search__status';
        li.textContent = text;
        list.appendChild(li);
        setOpen(true);
    }

    function choose(label) {
        hidden.value = label;
        input.value = label;
        closeList();
        updateSoapFinalizeReady();
        soapDraftNoteEdited();
    }

    function renderRows(rows) {
        list.innerHTML = '';
        rows.forEach(function (row) {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('role', 'option');
            const code = document.createElement('span');
            code.className = 'icd-search__code';
            code.textContent = row.code || '';
            const desc = document.createElement('span');
            desc.textContent = row.description || '';
            btn.appendChild(code);
            btn.appendChild(desc);
            btn.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            btn.addEventListener('click', function () { choose(row.label || ''); });
            li.appendChild(btn);
            list.appendChild(li);
        });
        setOpen(true);
    }

    function runSearch(q) {
        const seq = ++requestSeq;
        showStatus('Searching ICD-10…');
        fetch('<?= ASSET_BASE ?>/app/api/provider/icd10_search.php?q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, data: data };
            });
        }).then(function (payload) {
            if (seq !== requestSeq) return;
            const data = payload.data || {};
            if (!payload.ok || data.success === false) {
                showStatus(data.message || 'Could not load the ICD-10 list.');
                return;
            }
            const rows = data.results || [];
            if (!rows.length) {
                showStatus('No matching ICD-10 codes.');
                return;
            }
            renderRows(rows);
        }).catch(function () {
            if (seq !== requestSeq) return;
            showStatus('Could not load the ICD-10 list.');
        });
    }

    function queueSearch() {
        if (input.value.trim() !== hidden.value) hidden.value = '';
        window.clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) {
            showStatus('Type at least 2 characters to search ICD-10 codes and descriptions.');
            updateSoapFinalizeReady();
            return;
        }
        timer = window.setTimeout(function () { runSearch(q); }, 220);
        updateSoapFinalizeReady();
    }

    input.addEventListener('input', queueSearch);
    input.addEventListener('focus', queueSearch);
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeList();
            return;
        }
        if (event.key !== 'Enter') return;
        const first = list.querySelector('button');
        if (!first || list.hidden) return;
        event.preventDefault();
        first.click();
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('#icdSearch')) closeList();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSoapSignatureUi);
} else {
    initSoapSignatureUi();
}

// FINALIZE CONSULTATION
async function finalizeConsultation() {
    if (soapFinalizeBusy) return;
    const err = document.getElementById('soapSignError');
    const msg = soapClientValidationMessage();
    if (msg) {
        if (err) err.textContent = msg;
        return;
    }
    const urgencyMsg = soapFinalizeUrgencyValidationMessage();
    if (urgencyMsg) {
        const hint = document.getElementById('soapFinalUrgencyHint');
        if (hint) hint.textContent = urgencyMsg;
        if (err) err.textContent = urgencyMsg;
        return;
    }
    const confirmBtn = document.getElementById('soapFinalizeConfirm');
    const finalizeBtn = document.getElementById('soapFinalizeBtn');
    soapFinalizeBusy = true;
    if (window.soapDraftSaver) {
        soapDraftSaver.hold();
        await soapDraftSaver.whenIdle();
    }
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.dataset.label = confirmBtn.dataset.label || confirmBtn.textContent;
        confirmBtn.textContent = 'Saving…';
    }
    if (finalizeBtn) {
        finalizeBtn.disabled = true;
        finalizeBtn.dataset.label = finalizeBtn.dataset.label || finalizeBtn.textContent;
        finalizeBtn.textContent = 'Saving…';
    }
    const data = await saveSOAP(true);
    if (confirmBtn) {
        confirmBtn.disabled = false;
        confirmBtn.textContent = confirmBtn.dataset.label || 'Confirm';
    }
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
    soapFinalizeBusy = false;
    if (window.soapDraftSaver) soapDraftSaver.release();
    if (finalizeBtn) {
        finalizeBtn.textContent = finalizeBtn.dataset.label || 'Finalize SOAP Note';
    }
    updateSoapFinalizeReady();
    const failMsg = (data && data.message) ? data.message : 'Could not finalize consultation.';
    if (err) {
        err.textContent = failMsg;
    } else {
        alert(failMsg);
    }
}

async function issueReferral() {
    const typeEl = document.getElementById('referralType');
    const facilityEl = document.getElementById('referralFacility');
    const facilityOtherEl = document.getElementById('referralFacilityOther');
    const reasonEl = document.getElementById('referralReason');
    const notesEl = document.getElementById('referralNotes');
    const type = typeEl ? String(typeEl.value || '').trim() : '';
    const reason = reasonEl ? String(reasonEl.value || '').trim() : '';
    const notes = notesEl ? String(notesEl.value || '').trim() : '';
    if (!type) return alert('Select a referral type / service.');
    if (!reason || reason.length < 5) return alert('Enter a clear reason for referral (clinical note).');

    let facilityId = 0;
    let facilityName = '';
    if (facilityEl) {
        const facVal = String(facilityEl.value || '');
        if (facVal === '__other') {
            facilityName = facilityOtherEl ? String(facilityOtherEl.value || '').trim() : '';
            if (!facilityName) return alert('Specify the facility / destination.');
        } else if (facVal) {
            facilityId = parseInt(facVal, 10) || 0;
            const opt = facilityEl.options[facilityEl.selectedIndex];
            facilityName = opt ? String(opt.getAttribute('data-name') || opt.textContent || '').trim() : '';
        }
    }
    if (!facilityName && !facilityId) {
        return alert('Select a facility / destination for this referral.');
    }

    try {
        const fd = new FormData();
        fd.append('patient_id', sessionPatientId);
        fd.append('consultation_id', sessionConsultationId);
        fd.append('referral_type', type);
        fd.append('reason', reason);
        if (facilityId > 0) fd.append('facility_id', String(facilityId));
        if (facilityName) fd.append('facility_name', facilityName);
        if (notes) fd.append('provider_notes', notes);
        fd.append('csrf_token', sessionCsrf);
        const res = await fetch('<?= ASSET_BASE ?>/app/api/provider/create_referral.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        showSessionChatAlert(data.message || (data.success ? 'Referral issued.' : 'Could not create referral.'), data.success ? 'success' : 'error');
        if (data && data.success) {
            if (typeEl) typeEl.value = '';
            if (facilityEl) facilityEl.value = '';
            if (facilityOtherEl) {
                facilityOtherEl.value = '';
                facilityOtherEl.style.display = 'none';
            }
            if (reasonEl) reasonEl.value = '';
            if (notesEl) notesEl.value = '';
        }
    } catch (e) {
        showSessionChatAlert('Network error creating referral.', 'error');
    }
}

(function bindReferralFacilityOther() {
    const facilityEl = document.getElementById('referralFacility');
    const otherEl = document.getElementById('referralFacilityOther');
    if (!facilityEl || !otherEl) return;
    facilityEl.addEventListener('change', function () {
        const show = facilityEl.value === '__other';
        otherEl.style.display = show ? 'block' : 'none';
        if (!show) otherEl.value = '';
        if (show) otherEl.focus();
    });
})();

function scheduleFollowUp() {
    openFollowUpModal();
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

    fuSetStatus(
        opts && opts.fromCallEnd
            ? 'Video ended. Submit Final Assessment to complete this consultation.'
            : '',
        opts && opts.fromCallEnd ? 'ok' : ''
    );

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

    // After follow-up from call end, return to Final Assessment (SOAP), not a completed state.
    // No reload: unsaved SOAP text on this page must survive.
    if (fuOpenedFromCallEnd) {
        fuOpenedFromCallEnd = false;
        promptFinalAssessmentRequired();
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


