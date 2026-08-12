<?php
/**
 * In-call panels for video_room.php (info, chat, SOAP, post-call).
 * Expects: $is_patient, $consultation_id, $token
 */
?>
<div id="mcVcPanelBackdrop" class="mc-vc-side-backdrop" hidden aria-hidden="true"></div>
<aside id="mcVcSidePanel" class="mc-vc-side-panel" aria-label="Consultation details" hidden>
  <div class="mc-vc-side-panel__tabs" role="tablist">
    <button type="button" class="mc-vc-side-tab is-active" data-panel-tab="info" role="tab">Info</button>
    <button type="button" class="mc-vc-side-tab" data-panel-tab="chat" role="tab">Chat</button>
    <?php if (empty($is_patient)): ?>
    <button type="button" class="mc-vc-side-tab" data-panel-tab="soap" role="tab">SOAP</button>
    <?php endif; ?>
    <button type="button" class="mc-vc-side-panel__close" id="mcVcPanelClose" aria-label="Close panel">×</button>
  </div>
  <div class="mc-vc-side-panel__body">
    <div class="mc-vc-side-panel__pane is-active" data-panel-pane="info" id="mcVcInfoPane">
      <div class="mc-vc-info-loading">Loading consultation details…</div>
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
        <p class="mc-vc-soap-hint">Auto-saves during the call. Patient cannot see SOAP notes.</p>
        <p class="mc-vc-soap-status" id="mcVcSoapStatus" hidden></p>
      </form>
    </div>
    <?php endif; ?>
  </div>
</aside>

<button type="button" class="mc-vc-panel-toggle" id="mcVcPanelToggle" title="Consultation details" aria-label="Open consultation panel">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
</button>

<div id="mcVcPostCallModal" class="mc-vc-postcall" hidden role="dialog" aria-modal="true" aria-labelledby="mcVcPostCallTitle">
  <div class="mc-vc-postcall__card">
    <h2 id="mcVcPostCallTitle">Consultation ended</h2>
    <p class="mc-vc-postcall__sub">Thank you for using medConnect telemedicine.</p>
    <div class="mc-vc-postcall__actions">
      <?php if (!empty($is_patient)): ?>
      <a href="<?= htmlspecialchars(ASSET_BASE) ?>/views/patient/triage.php" class="mc-vc-postcall__btn mc-vc-postcall__btn--primary">Schedule Follow-up</a>
      <a href="<?= htmlspecialchars(ASSET_BASE) ?>/views/patient/my_health.php?tab=files#health-file-<?= (int) $consultation_id ?>" class="mc-vc-postcall__btn">View Health Files</a>
      <a href="<?= htmlspecialchars(ASSET_BASE) ?>/views/patient/my_health.php?tab=timeline" class="mc-vc-postcall__btn">My Health</a>
      <?php else: ?>
      <button type="button" class="mc-vc-postcall__btn mc-vc-postcall__btn--primary" id="mcVcPostCallFollowup">Schedule Follow-up</button>
      <a href="<?= htmlspecialchars(ASSET_BASE) ?>/views/provider/consultation_session.php?id=<?= (int) $consultation_id ?>" class="mc-vc-postcall__btn">Return to Session</a>
      <a href="<?= htmlspecialchars(ASSET_BASE) ?>/views/provider/dashboard.php" class="mc-vc-postcall__btn">Dashboard</a>
      <?php endif; ?>
    </div>
    <button type="button" class="mc-vc-postcall__dismiss" id="mcVcPostCallDismiss">Close</button>
  </div>
</div>
