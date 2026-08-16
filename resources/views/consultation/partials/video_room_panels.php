<?php
/**
 * In-call panels for video_room.php (info, chat, SOAP, post-call).
 * Expects: $is_patient, $consultation_id, $token, $session, and seed fields from video_room.php
 */
$info_title = !empty($is_patient)
    ? (trim((string) ($provider_name ?? '')) !== ''
        ? ((preg_match('/^dr\.?\s/i', (string) $provider_name) ? $provider_name : 'Dr. ' . $provider_name))
        : 'Your healthcare provider')
    : (trim((string) ($patient_name ?? '')) !== '' ? $patient_name : 'Patient');
$info_sub = !empty($is_patient)
    ? (string) ($provider_specialty ?? 'General Medicine')
    : trim(
        (!empty($patient_age_seed) ? $patient_age_seed . ' yrs' : '—')
        . ' · ' . (trim((string) ($session['patient_sex'] ?? '')) !== '' ? (string) $session['patient_sex'] : '—')
    );
?>
<div id="mcVcPanelBackdrop" class="mc-vc-side-backdrop" hidden aria-hidden="true"></div>
<aside id="mcVcSidePanel" class="mc-vc-side-panel" aria-label="<?= !empty($is_patient) ? 'Consultation details' : 'Patient information' ?>" hidden>
  <div class="mc-vc-side-panel__tabs" role="tablist">
    <button type="button" class="mc-vc-side-tab is-active" data-panel-tab="info" role="tab"><?= !empty($is_patient) ? 'Details' : 'Patient Info' ?></button>
    <button type="button" class="mc-vc-side-tab" data-panel-tab="chat" role="tab">Chat</button>
    <?php if (empty($is_patient)): ?>
    <button type="button" class="mc-vc-side-tab" data-panel-tab="soap" role="tab">SOAP</button>
    <?php endif; ?>
    <button type="button" class="mc-vc-side-panel__close" id="mcVcPanelClose" aria-label="Close panel" title="Close">×</button>
  </div>
  <div class="mc-vc-side-panel__body">
    <div class="mc-vc-side-panel__pane is-active" data-panel-pane="info" id="mcVcInfoPane">
      <div class="mc-vc-info-card" id="mcVcInfoSeed">
        <h3 class="mc-vc-info-card__title"><?= htmlspecialchars($info_title) ?></h3>
        <p class="mc-vc-info-card__sub"><?= htmlspecialchars($info_sub) ?></p>
        <dl class="mc-vc-info-dl">
          <?php if (empty($is_patient)): ?>
          <div><dt>Patient ID</dt><dd><?= htmlspecialchars((string) ($patient_number ?? '—')) ?></dd></div>
          <?php endif; ?>
          <div><dt>Consultation</dt><dd>#<?= (int) $consultation_id ?></dd></div>
          <div><dt>Appointment</dt><dd><?= htmlspecialchars((string) ($appointment_label ?? '—') !== '' ? $appointment_label : '—') ?></dd></div>
          <div><dt>Chief complaint</dt><dd><?= htmlspecialchars((string) ($chief_complaint_seed ?? '') !== '' ? $chief_complaint_seed : '—') ?></dd></div>
        </dl>
        <p class="mc-vc-info-refresh" id="mcVcInfoStatus">Refreshing live details…</p>
        <button type="button" class="mc-vc-info-retry" id="mcVcInfoRetry" hidden>Retry loading details</button>
      </div>
    </div>
    <div class="mc-vc-side-panel__pane" data-panel-pane="chat" id="mcVcChatPane">
      <div class="mc-vc-chat-log" id="mcVcChatLog" aria-live="polite"></div>
      <form class="mc-vc-chat-compose" id="mcVcChatForm">
        <input type="file" id="mcVcChatFile" accept="image/*,.pdf,.doc,.docx" hidden />
        <button type="button" class="mc-vc-chat-attach" id="mcVcChatAttach" title="Attach file">📎</button>
        <textarea id="mcVcChatInput" rows="2" maxlength="2000" placeholder="Type a secure message…"></textarea>
        <button type="submit" class="mc-vc-chat-send">Send</button>
      </form>
    </div>
    <?php if (empty($is_patient)): ?>
    <div class="mc-vc-side-panel__pane" data-panel-pane="soap" id="mcVcSoapPane">
      <form id="mcVcSoapForm" class="mc-vc-soap-form">
        <input type="hidden" name="patient_id" value="<?= (int) ($session['patient_id'] ?? 0) ?>">
        <label>Subjective<textarea name="subjective" rows="2"></textarea></label>
        <label>Objective<textarea name="objective" rows="2"></textarea></label>
        <label>Assessment<textarea name="assessment" rows="2"></textarea></label>
        <label>Plan<textarea name="plan" rows="2"></textarea></label>
        <p class="mc-vc-soap-hint">Draft only — the patient cannot see these notes until you finalize SOAP after the visit.</p>
        <p class="mc-vc-soap-status" id="mcVcSoapStatus" hidden></p>
      </form>
    </div>
    <?php endif; ?>
  </div>
</aside>

<?php /* The floating hamburger opened this same panel as the Info and Chat buttons
         in the control bar. It was a third entry point that also floated over the
         video on small screens, so the control bar is now the only way in. */ ?>

<div id="mcVcPostCallModal" class="mc-vc-postcall" hidden role="dialog" aria-modal="true" aria-labelledby="mcVcPostCallTitle">
  <div class="mc-vc-postcall__card">
    <?php
      $postcallProvider = trim((string) ($provider_name ?? ''));
      if ($postcallProvider !== '' && !preg_match('/^dr\.?\s/i', $postcallProvider)) {
          $postcallProvider = 'Dr. ' . $postcallProvider;
      }
      $postcallDate = !empty($session['consult_date']) ? date('F j, Y', strtotime((string) $session['consult_date'])) : '';
      $postcallHasRecording = false;
      if (!empty($is_patient) && isset($pdo) && $pdo instanceof PDO && (int) $consultation_id > 0) {
          require_once BASE_PATH . '/app/includes/consultation_video_history.php';
          $postcallHasRecording = consultation_video_recording_view_url((int) $consultation_id) !== '';
      }
    ?>
    <?php if (!empty($is_patient)): ?>
    <div class="mc-vc-postcall__brand">
      <img src="<?= htmlspecialchars(ASSET_BASE) ?>/assets/img/medcon_logo.png" width="28" height="28" alt="">
      <span>medConnect</span>
    </div>
    <div class="mc-vc-postcall__hero">
      <div class="mc-vc-postcall__check" aria-hidden="true">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
      </div>
      <h2 id="mcVcPostCallTitle">Consultation Completed</h2>
      <p class="mc-vc-postcall__sub">
        Your video consultation with
        <strong id="mcVcPostCallDoctor"><?= htmlspecialchars($postcallProvider !== '' ? $postcallProvider : 'your doctor') ?></strong>
        has ended successfully.
      </p>
    </div>
    <section class="mc-vc-postcall__summary" aria-label="Consultation summary">
      <h2>Consultation Summary</h2>
      <dl class="mc-vc-postcall__meta">
        <div id="mcVcPostCallDateRow"<?= $postcallDate === '' ? ' hidden' : '' ?>>
          <dt>
            <span class="mc-vc-postcall__ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>
            Date
          </dt>
          <dd id="mcVcPostCallDate"><?= htmlspecialchars($postcallDate) ?></dd>
        </div>
        <div>
          <dt>
            <span class="mc-vc-postcall__ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
            Provider
          </dt>
          <dd id="mcVcPostCallProvider"><?= htmlspecialchars($postcallProvider !== '' ? $postcallProvider : 'your doctor') ?></dd>
        </div>
        <div>
          <dt>
            <span class="mc-vc-postcall__ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
            Status
          </dt>
          <dd>Completed</dd>
        </div>
        <div id="mcVcPostCallDurationRow">
          <dt>
            <span class="mc-vc-postcall__ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
            Duration
          </dt>
          <dd id="mcVcPostCallDuration">—</dd>
        </div>
      </dl>
    </section>
    <div class="mc-vc-postcall__confirm">
      <p class="mc-vc-postcall__confirm-title">Consultation saved successfully</p>
      <p class="mc-vc-postcall__confirm-copy">Your consultation record is now available in My Sessions.</p>
      <?php if ($postcallHasRecording): ?>
      <p class="mc-vc-postcall__confirm-copy">A video recording is available in this session.</p>
      <?php endif; ?>
    </div>
    <div class="mc-vc-postcall__actions">
      <a
        href="<?= htmlspecialchars(ASSET_BASE) ?>/views/patient/consultation_detail.php?id=<?= (int) $consultation_id ?>&amp;from=sessions"
        class="mc-vc-postcall__btn mc-vc-postcall__btn--primary"
        id="mcVcPostCallViewSession"
        target="_top"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
        View Session
      </a>
      <a
        href="<?= htmlspecialchars(ASSET_BASE) ?>/views/patient/dashboard.php"
        class="mc-vc-postcall__btn"
        target="_top"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
        Return to Dashboard
      </a>
    </div>
    <?php else: ?>
    <h2 id="mcVcPostCallTitle">Consultation ended</h2>
    <p class="mc-vc-postcall__sub">The session has been saved. Next, choose whether a follow-up is required, then complete SOAP notes.</p>
    <div class="mc-vc-postcall__actions">
      <a href="<?= htmlspecialchars(ASSET_BASE) ?>/views/provider/consultation_session.php?id=<?= (int) $consultation_id ?>&amp;followup=1" class="mc-vc-postcall__btn mc-vc-postcall__btn--primary" target="_top">Continue</a>
    </div>
    <?php endif; ?>
  </div>
</div>
