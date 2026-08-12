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

<button type="button" class="mc-vc-panel-toggle" id="mcVcPanelToggle" title="<?= !empty($is_patient) ? 'Consultation details' : 'Patient Info' ?>" aria-label="<?= !empty($is_patient) ? 'Open consultation details' : 'Open patient information' ?>">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
</button>

<div id="mcVcPostCallModal" class="mc-vc-postcall" hidden role="dialog" aria-modal="true" aria-labelledby="mcVcPostCallTitle">
  <div class="mc-vc-postcall__card">
    <?php if (!empty($is_patient)): ?>
    <h2 id="mcVcPostCallTitle">Consultation Completed</h2>
    <p class="mc-vc-postcall__sub">
      Your video consultation with
      <strong id="mcVcPostCallDoctor"><?= htmlspecialchars($provider_name !== '' ? (preg_match('/^dr\.?\s/i', $provider_name) ? $provider_name : 'Dr. ' . $provider_name) : 'your doctor') ?></strong>
      has ended.
    </p>
    <dl class="mc-vc-postcall__meta">
      <div>
        <dt>Date</dt>
        <dd id="mcVcPostCallDate"><?= !empty($session['consult_date']) ? htmlspecialchars(date('F j, Y', strtotime((string) $session['consult_date']))) : '—' ?></dd>
      </div>
      <div id="mcVcPostCallDurationRow" hidden>
        <dt>Duration</dt>
        <dd id="mcVcPostCallDuration"></dd>
      </div>
    </dl>
    <p class="mc-vc-postcall__saved">Your consultation has been saved to My Sessions.</p>
    <div class="mc-vc-postcall__actions">
      <a
        href="<?= htmlspecialchars(ASSET_BASE) ?>/views/patient/consultation_detail.php?id=<?= (int) $consultation_id ?>&amp;from=sessions"
        class="mc-vc-postcall__btn mc-vc-postcall__btn--primary"
        id="mcVcPostCallViewSession"
        target="_top"
      >View Session</a>
      <a
        href="<?= htmlspecialchars(ASSET_BASE) ?>/views/patient/dashboard.php"
        class="mc-vc-postcall__btn"
        target="_top"
      >Return to Dashboard</a>
    </div>
    <?php else: ?>
    <h2 id="mcVcPostCallTitle">Consultation ended</h2>
    <p class="mc-vc-postcall__sub">Complete SOAP documentation next. Notes are not visible to the patient until you finalize them.</p>
    <div class="mc-vc-postcall__actions">
      <a href="<?= htmlspecialchars(ASSET_BASE) ?>/views/provider/consultation_session.php?id=<?= (int) $consultation_id ?>&amp;soap=1#soapDocumentation" class="mc-vc-postcall__btn mc-vc-postcall__btn--primary">Document SOAP</a>
      <button type="button" class="mc-vc-postcall__btn" id="mcVcPostCallFollowup">Schedule Follow-up</button>
      <a href="<?= htmlspecialchars(ASSET_BASE) ?>/views/provider/dashboard.php" class="mc-vc-postcall__btn">Dashboard</a>
    </div>
    <button type="button" class="mc-vc-postcall__dismiss" id="mcVcPostCallDismiss">Close</button>
    <?php endif; ?>
  </div>
</div>
