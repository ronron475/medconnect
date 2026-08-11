<?php
/**
 * Book consultation + visit history (AI runs silently — not shown to patients).
 * Expects: $active_consultation, $booking_providers, $booking_today_ymd, $booking_today_label,
 *          $triage_history, $registration_chief_complaint (optional),
 *          $chief_complaint_locked, $chief_complaint_source, $active_chief_complaint_triage_id (optional)
 */
require_once __DIR__ . '/triage_helpers.php';

$registration_chief_complaint = trim((string) ($registration_chief_complaint ?? ''));
$chief_complaint_locked = isset($chief_complaint_locked)
    ? (bool) $chief_complaint_locked
    : ($registration_chief_complaint !== '');
$chief_complaint_source = trim((string) ($chief_complaint_source ?? ''));
$chief_complaint_source_label = patient_portal_complaint_source_label($chief_complaint_source);
$active_chief_complaint_triage_id = (int) ($active_chief_complaint_triage_id ?? 0);
$show_evidence_section = false;
$review_booking_ctx = $review_booking_ctx ?? ['locked' => false, 'provider_id' => 0, 'provider_name' => ''];
$locked_provider_id = (int) ($locked_provider_id ?? 0);
$locked_provider_name = trim((string) ($locked_provider_name ?? ''));
$locked_assigned_has_slots = !empty($locked_assigned_has_slots);
$locked_alternate_available = !empty($locked_alternate_available);
?>
<h2 class="text-h2 mb-md">Book Consultation</h2>
<?php if (!empty($review_booking_ctx['locked']) && $locked_provider_name !== ''): ?>
<p class="text-sm text-muted patient-triage-lead">
  Your care tips doctor is <strong><?= htmlspecialchars($locked_provider_name) ?></strong>.
  Choose an available time below to book your video visit.
</p>
<?php else: ?>
<p class="text-sm text-muted patient-triage-lead">
  Choose a doctor and an available time slot for your video visit.
  <?php if (!empty($patient_has_completed_visit) || !empty($patient_has_scheduled_followup)): ?>
  Enter a new chief complaint below for a separate consultation — follow-ups and past visits stay on your record.
  <?php endif; ?>
</p>
<?php endif; ?>

<?php if (!empty($future_scheduled_consultation) || !empty($patient_has_scheduled_followup)): ?>
<div class="patient-triage-alert patient-triage-alert--success is-visible patient-triage-alert--spaced">
  <?php if (!empty($future_scheduled_consultation)): ?>
    You have an appointment scheduled<?= $booking_future_label !== '' ? ' for ' . htmlspecialchars($booking_future_label) : '' ?>.
  <?php endif; ?>
  <?php if (!empty($patient_has_scheduled_followup)): ?>
    <?= !empty($future_scheduled_consultation) ? 'Your doctor also scheduled a follow-up.' : 'Your doctor scheduled a follow-up for you.' ?>
  <?php endif; ?>
  You can still book a <strong>new consultation</strong> here with a different chief complaint — your follow-up appointment will not be changed.
</div>
<?php elseif (!empty($active_consultation)): ?>
<div class="patient-triage-alert patient-triage-alert--warning is-visible patient-triage-alert--spaced">
  <?php if (($active_consultation['status'] ?? '') === 'in_consultation'): ?>
    You currently have a consultation in progress. A new slot cannot be booked until that visit is completed.
  <?php elseif (!empty($booking_same_day_reschedule)): ?>
    You already have an open appointment<?= !empty($active_consultation['consult_date']) ? ' on ' . htmlspecialchars(date('M j, Y', strtotime($active_consultation['consult_date']))) : '' ?>.
    Submitting here will update it to your newly selected slot for today.
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($review_booking_ctx['locked']) && $locked_provider_name !== ''): ?>
<div class="patient-triage-alert patient-triage-alert--warning is-visible patient-triage-alert--spaced">
  Your self-care guidance was reviewed by <strong><?= htmlspecialchars($locked_provider_name) ?></strong>.
  Please book your online consultation with the same doctor unless an administrator changes your assignment.
</div>
<?php endif; ?>

<?php if (empty($booking_providers)): ?>
<div class="patient-triage-alert patient-triage-alert--error is-visible patient-triage-alert--spaced">
  No providers are available for booking right now. Please contact the health office.
</div>
<?php endif; ?>

<div class="mc-card patient-triage-form">
  <h3 class="text-h3 mb-md">Schedule Your Visit</h3>
  <div id="triageFormAlert" class="patient-triage-alert" role="alert"></div>
  <form id="patientTriageForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($active_chief_complaint_triage_id > 0): ?>
    <input type="hidden" name="triage_id" value="<?= (int) $active_chief_complaint_triage_id ?>">
    <?php endif; ?>
    <div class="form-group" id="chief-complaint">
      <label class="form-label" for="chief_complaint">
        Chief Complaint<?= $chief_complaint_locked ? ' <span class="text-muted">(' . htmlspecialchars($chief_complaint_source_label) . ')</span>' : '' ?>
      </label>
      <textarea
        id="chief_complaint"
        name="chief_complaint"
        class="form-control"
        rows="<?= $chief_complaint_locked ? 2 : 3 ?>"
        placeholder="<?= $chief_complaint_locked ? 'Your submitted health concern…' : 'Describe your current health concern...' ?>"
        maxlength="500"
        <?= $chief_complaint_locked ? 'readonly aria-readonly="true"' : 'required' ?>
      ><?= htmlspecialchars($registration_chief_complaint) ?></textarea>
      <p class="text-xs text-muted" style="margin-top:6px;">
        <?php if ($chief_complaint_locked): ?>
        This chief complaint is already on file and will be reviewed by your doctor. It cannot be changed while this consultation is still active.
        <?php else: ?>
        Share your current health concern to start a new consultation. Previous complaints stay in My Sessions and are not reused.
        <?php endif; ?>
      </p>
    </div>

    <div
      class="form-group complaint-evidence-group<?= $show_evidence_section ? '' : ' complaint-evidence-group--collapsed' ?>"
      id="complaintEvidenceSection"
      <?= $show_evidence_section ? '' : 'hidden inert aria-hidden="true"' ?>
    >
      <label class="form-label" for="supporting_evidence">
        Supporting Evidence <span class="text-muted">(optional)</span>
      </label>
      <p class="text-xs text-muted complaint-evidence-hint">
        Upload a photo or short video of your concern for your doctor to review.
        This does not affect triage or appointment priority.
      </p>
      <div class="complaint-evidence-upload">
        <input
          type="file"
          id="supporting_evidence"
          name="supporting_evidence"
          class="complaint-evidence-input"
          accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"
          <?= $show_evidence_section ? '' : 'disabled tabindex="-1"' ?>
        />
        <button
          type="button"
          class="mc-btn mc-btn--outline complaint-evidence-choose"
          id="btnChooseEvidence"
          <?= $show_evidence_section ? '' : 'disabled' ?>
        >
          Choose photo or video
        </button>
        <span class="complaint-evidence-filename text-xs text-muted" id="evidenceFilename" hidden></span>
        <button type="button" class="complaint-evidence-remove" id="btnRemoveEvidence" hidden aria-label="Remove supporting evidence">
          Remove
        </button>
      </div>
      <div class="complaint-evidence-preview" id="evidencePreview" hidden></div>
      <p class="text-xs text-muted" style="margin-top:6px;">Photos up to 5 MB. Videos up to 25 MB (MP4 or WebM).</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="booking_provider"><?= !empty($review_booking_ctx['locked']) ? 'Your assigned doctor' : 'Choose provider' ?></label>
      <select id="booking_provider" name="provider_id" class="form-control" <?= !empty($review_booking_ctx['locked']) ? 'disabled aria-readonly="true"' : 'required' ?>>
        <option value="">Select a provider…</option>
        <?php foreach ($booking_providers as $provider): ?>
        <option value="<?= (int) $provider['id'] ?>"<?= !empty($review_booking_ctx['locked']) && (int) $provider['id'] === $locked_provider_id ? ' selected' : '' ?>><?= htmlspecialchars($provider['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($review_booking_ctx['locked']) && $locked_provider_id > 0): ?>
      <input type="hidden" name="provider_id" value="<?= (int) $locked_provider_id ?>" />
      <?php endif; ?>
      <?php if (!empty($review_booking_ctx['locked'])): ?>
      <p class="text-xs text-muted" style="margin-top:6px;">Same doctor as your care tips review<?= $locked_assigned_has_slots ? ' — open slots today below.' : '.' ?></p>
      <?php endif; ?>
    </div>

    <?php if (!empty($review_booking_ctx['locked']) && $locked_provider_name !== '' && $locked_assigned_has_slots): ?>
    <div class="patient-triage-alert patient-triage-alert--success is-visible patient-triage-alert--spaced-sm" role="status">
      <strong><?= htmlspecialchars($locked_provider_name) ?></strong> has open clinic times today. Pick a slot below to book your video visit.
    </div>
    <?php endif; ?>

    <?php if (!empty($review_booking_ctx['locked']) && !$locked_assigned_has_slots && $locked_alternate_available): ?>
    <div id="bookingAlternatePanel" class="patient-triage-alert patient-triage-alert--warning is-visible patient-triage-alert--spaced-sm" role="status">
      <p class="patient-triage-alert__line"><strong><?= htmlspecialchars($locked_provider_name) ?></strong> has no open slots left today.</p>
      <p class="text-sm text-muted patient-triage-alert__line">You can request the <strong>next available doctor</strong> who has clinic hours today. Your care tips review will move to that doctor too.</p>
      <button type="button" class="mc-btn mc-btn--outline patient-triage-alt-btn" id="btnRequestAlternateProvider">
        Request next available doctor
      </button>
      <p id="bookingAlternateStatus" class="text-xs text-muted" style="margin:10px 0 0;" hidden role="alert"></p>
    </div>
    <?php elseif (!empty($review_booking_ctx['locked']) && !$locked_assigned_has_slots && !$locked_alternate_available): ?>
    <div class="patient-triage-alert patient-triage-alert--warning is-visible patient-triage-alert--spaced-sm" role="status">
      <strong><?= htmlspecialchars($locked_provider_name) ?></strong> has no open slots today, and no other doctor has clinic hours right now.
      Please contact the City Health Office or try again on the next clinic day.
    </div>
    <?php endif; ?>

    <div class="form-group">
      <label class="form-label" for="booking_date_display">Appointment date (today only)</label>
      <div
        id="booking_date_display"
        class="booking-today-date"
        data-today="<?= htmlspecialchars($booking_today_ymd) ?>"
      >Today — <?= htmlspecialchars($booking_today_label) ?></div>
      <input
        type="hidden"
        id="booking_date"
        name="booking_date"
        value="<?= htmlspecialchars($booking_today_ymd) ?>"
      >
      <p class="text-xs text-muted booking-today-hint">
        Only today&apos;s clinic hours set by the doctor are shown below.
      </p>
    </div>

    <div class="form-group">
      <label class="form-label">Available time slots (today)</label>
      <div id="bookingSlotsWrap" class="booking-slots-wrap">
        <p class="text-xs text-muted">Select a provider to load today&apos;s available slots.</p>
      </div>
      <input type="hidden" id="booking_slot_id" name="slot_id" value="">
    </div>

    <button type="submit" class="mc-btn mc-btn--primary patient-triage-submit" id="patientTriageSubmit">
      <?= !empty($review_booking_ctx['locked']) ? 'Book Appointment' : 'Submit / Book Appointment' ?>
    </button>
    <?php if (empty($review_booking_ctx['locked'])): ?>
    <p class="text-xs text-muted patient-triage-submit-hint">
      For non-urgent cases, you may submit without choosing a time slot to request provider-reviewed self-care guidance first.
      Select a slot only when you are ready to book a consultation.
    </p>
    <?php endif; ?>
  </form>
</div>

<!-- Silent booking overlay (no technical AI output) -->
<?php
if (!function_exists('mc_render_loader_panel')) {
    require_once dirname(__DIR__, 2) . '/components/loader.php';
}
mc_render_loader_panel([
    'id' => 'patient-booking-overlay',
    'title' => 'Preparing your appointment…',
    'sub' => 'Please wait while we securely process your request and confirm your consultation slot.',
    'progress' => true,
    'steps_id' => 'patient-booking-overlay-steps',
]);
?>

<h3 class="text-h3 mb-md patient-triage-history-title">Visit History</h3>
<p class="text-muted patient-triage-history-lead">
  Submitted health concerns and triage assessments — confirmed only when status shows <strong>Visit booked</strong>.
</p>
<div class="mc-card patient-triage-history">
  <div class="mc-table-wrap">
    <table class="mc-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Health concern</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($triage_history)): ?>
          <tr><td colspan="3"><div class="mc-table-empty"><p>No previous visits recorded yet. Book your first consultation above.</p></div></td></tr>
        <?php else: foreach ($triage_history as $t): ?>
          <tr>
            <td data-label="Date" style="font-weight: 700; color: var(--mc-navy-dark);"><?= !empty($t['assessed_at']) ? date('M j, Y', strtotime($t['assessed_at'])) : '—' ?></td>
            <td data-label="Concern" class="triage-symptoms-cell"><?= htmlspecialchars($t['chief_complaint'] ?? '—') ?></td>
            <td data-label="Status">
              <span class="badge-risk <?= mc_patient_visit_status_class($t) ?>"><?= mc_patient_visit_status_label($t, $pdo ?? null, (int) ($uid ?? 0)) ?></span>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=care-tips" class="patient-triage-care-link">
  <span class="patient-triage-care-link__text">Approved care tips</span>
  <span class="patient-triage-care-link__meta">My Health · Care tips</span>
  <svg class="patient-triage-care-link__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
</a>
