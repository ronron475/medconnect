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
$review_booking_ctx = $review_booking_ctx ?? ['locked' => false, 'provider_id' => 0, 'provider_name' => ''];
$locked_provider_id = (int) ($locked_provider_id ?? 0);
$locked_provider_name = trim((string) ($locked_provider_name ?? ''));
$locked_assigned_has_slots = !empty($locked_assigned_has_slots);
$locked_alternate_available = !empty($locked_alternate_available);
$is_provider_locked = !empty($review_booking_ctx['locked']) && $locked_provider_id > 0;
$preliminary_complaint_triage = is_array($preliminary_complaint_triage ?? null) ? $preliminary_complaint_triage : null;
$preliminary_payload = null;
if ($preliminary_complaint_triage && !$is_provider_locked && !$chief_complaint_locked) {
    $prelimLevel = (string) ($preliminary_complaint_triage['triage_level'] ?? 'non_urgent');
    $prelimClass = (string) ($preliminary_complaint_triage['triage_classification'] ?? '');
    $prelimLabel = function_exists('patient_symptoms_review_classification_label')
        ? patient_symptoms_review_classification_label($prelimLevel, $prelimClass)
        : 'NON-URGENT';
    $prelimComplaint = trim((string) ($preliminary_complaint_triage['chief_complaint'] ?? ''));
    $preliminary_payload = [
        'triage_id' => (int) ($preliminary_complaint_triage['id'] ?? 0),
        'triage_level' => $prelimLevel,
        'classification_label' => $prelimLabel,
        'chief_complaint' => $prelimComplaint,
    ];
    if ($registration_chief_complaint === '' && $prelimComplaint !== '') {
        $registration_chief_complaint = $prelimComplaint;
    }
}
$preliminary_json = $preliminary_payload ? json_encode($preliminary_payload, JSON_UNESCAPED_UNICODE) : '';
$assigned_display_name = $locked_provider_name !== '' ? $locked_provider_name : '';
?>
<h2 class="text-h2 mb-md patient-triage-page__title">Book Consultation</h2>
<?php if (!empty($review_booking_ctx['locked']) && $locked_provider_name !== ''): ?>
<p class="text-sm text-muted patient-triage-lead">
  Your care tips doctor is <strong><?= htmlspecialchars($locked_provider_name) ?></strong>.
  Choose an available time below to book your video visit.
</p>
<?php else: ?>
<p class="text-sm text-muted patient-triage-lead">
  Share your primary complaint. The system assigns a doctor from real available schedules — you do not choose the provider.
  <?php if (!empty($patient_has_completed_visit) || !empty($patient_has_scheduled_followup)): ?>
  Enter a new primary complaint below for a separate consultation — follow-ups and past visits stay on your record.
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
  You can still book a <strong>new consultation</strong> here with a different primary complaint — your follow-up appointment will not be changed.
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

<?php if (!empty($review_booking_ctx['locked']) && $locked_provider_id <= 0 && empty($booking_providers)): ?>
<div class="patient-triage-alert patient-triage-alert--error is-visible patient-triage-alert--spaced">
  No providers are available for booking right now. Please contact the health office.
</div>
<?php endif; ?>

<div class="mc-card patient-triage-form">
  <h3 class="text-h3 mb-md">Schedule Your Visit</h3>
  <div id="triageFormAlert" class="patient-triage-alert" role="alert"></div>
  <form
    id="patientTriageForm"
    novalidate
    <?php if ($preliminary_json !== ''): ?>
    data-preliminary="<?= htmlspecialchars($preliminary_json, ENT_QUOTES, 'UTF-8') ?>"
    <?php endif; ?>
  >
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" id="booking_triage_id" name="triage_id" value="<?= (int) ($active_chief_complaint_triage_id > 0 ? $active_chief_complaint_triage_id : ($preliminary_payload['triage_id'] ?? 0)) ?>">
    <?php if (!empty($force_new_concern)): ?>
    <input type="hidden" name="new_concern" value="1">
    <?php endif; ?>
    <div class="form-group" id="chief-complaint">
      <label class="form-label" for="chief_complaint">
        Primary Complaint<?= $chief_complaint_locked ? ' <span class="text-muted">(' . htmlspecialchars($chief_complaint_source_label) . ')</span>' : '' ?>
      </label>
      <textarea
        id="chief_complaint"
        name="chief_complaint"
        class="form-control"
        rows="<?= $chief_complaint_locked ? 2 : 3 ?>"
        placeholder="<?= $chief_complaint_locked ? 'Your submitted primary complaint…' : 'Describe your primary complaint...' ?>"
        maxlength="500"
        <?= $chief_complaint_locked ? 'readonly aria-readonly="true"' : 'required' ?>
      ><?= htmlspecialchars($registration_chief_complaint) ?></textarea>
      <p class="text-xs text-muted" style="margin-top:6px;">
        <?php if ($chief_complaint_locked): ?>
        This primary complaint is already on file and will be reviewed by your doctor. It cannot be changed while this consultation is still active.
        <?php if (empty($active_consultation) && empty($force_new_concern)): ?>
        If this is a different primary complaint, <a href="<?= htmlspecialchars((defined('ASSET_BASE') ? ASSET_BASE : '') . '/views/patient/triage.php?new_concern=1') ?>">start a new case</a>.
        <?php endif; ?>
        <?php else: ?>
        Describe your primary complaint to start a new consultation. Previous complaints stay in My Sessions and are not reused.
        <?php endif; ?>
      </p>
    </div>

    <div id="triageAiResult" class="pdash-care-ai-result<?= $preliminary_payload ? ' is-visible' : '' ?>" <?= $preliminary_payload ? '' : 'hidden' ?>>
      <p class="pdash-care-ai-result__label">
        Preliminary AI Assessment:
        <strong id="triageAiLevel"><?= htmlspecialchars((string) ($preliminary_payload['classification_label'] ?? 'NON-URGENT')) ?></strong>
      </p>
      <p id="triageContinueHint" class="pdash-care-continue" role="status">
        Please click &ldquo;Submit patient complaint&rdquo; again to continue.
      </p>
    </div>

    <?php if (!$is_provider_locked): ?>
    <button type="submit" class="mc-btn mc-btn--primary patient-triage-submit" id="patientTriageSubmit">
      Submit patient complaint
    </button>
    <p class="text-xs text-muted patient-triage-submit-hint">
      Click once for the AI preliminary assessment. Click <strong>Submit patient complaint</strong> again to assign a doctor from real available slots.
    </p>
    <?php endif; ?>

    <div class="form-group" id="bookingAssignedProviderWrap">
      <label class="form-label" id="bookingAssignedProviderLabel">Automatically Assigned Provider</label>
      <div
        id="bookingAssignedProvider"
        class="booking-assigned-provider<?= $is_provider_locked ? ' is-assigned' : ' is-pending' ?>"
        role="status"
      >
        <p id="bookingAssignedProviderName" class="booking-assigned-provider__name">
          <?php if ($is_provider_locked && $assigned_display_name !== ''): ?>
          Dr. <?= htmlspecialchars($assigned_display_name) ?>
          <?php else: ?>
          Waiting for assignment…
          <?php endif; ?>
        </p>
        <p class="booking-assigned-provider__hint">
          <?php if ($is_provider_locked): ?>
          Provider automatically selected based on your triage result, provider availability, appointment slots, and workload.
          <?php else: ?>
          Your doctor appears here after you submit your primary complaint twice. You cannot choose a provider manually.
          <?php endif; ?>
        </p>
      </div>
      <input type="hidden" id="booking_provider" name="provider_id" value="<?= $is_provider_locked ? (int) $locked_provider_id : '' ?>" autocomplete="off">
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
      <?php if ($locked_provider_name !== ''): ?>
      <strong><?= htmlspecialchars($locked_provider_name) ?></strong> has no open slots today, and no other doctor has clinic hours right now.
      <?php else: ?>
      No doctor has clinic hours right now.
      <?php endif; ?>
      You are in the waiting queue (Waiting for Doctor Availability). We will notify you by email when a consultation slot becomes available — you do not need to start over.
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
        <p class="text-xs text-muted"><?= $is_provider_locked ? 'Available times for your assigned doctor today.' : 'Appointment times appear after the system assigns your doctor.' ?></p>
      </div>
      <input type="hidden" id="booking_slot_id" name="slot_id" value="">
    </div>

    <?php if ($is_provider_locked): ?>
    <button type="submit" class="mc-btn mc-btn--primary patient-triage-submit" id="patientTriageSubmit">
      Book Appointment
    </button>
    <?php endif; ?>
  </form>
  <div id="patientTriageBookedPanel" class="patient-triage-booked" hidden>
    <p class="patient-triage-booked__hint">Your video visit is confirmed. Open My Sessions when it is time to join, or change the time if you still need a different slot today.</p>
    <div class="patient-triage-booked__actions">
      <a class="mc-btn mc-btn--primary" href="<?= htmlspecialchars((defined('ASSET_BASE') ? ASSET_BASE : '') . '/views/patient/consultations.php') ?>">View my session</a>
      <button type="button" class="mc-btn mc-btn--outline" id="patientTriageChangeTime">Change time</button>
    </div>
  </div>
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
  Submitted health concerns with the original AI assessment, the doctor’s final assessment, and the official decision.
  A visit is confirmed only when status shows <strong>Visit booked</strong>.
</p>
<div class="mc-card patient-triage-history">
  <?php if (!empty($triage_history)): ?>
  <div class="patient-triage-history__filters" id="visitHistoryFilters">
    <label class="patient-triage-history__field patient-triage-history__field--search">
      <span class="patient-triage-history__field-label">Search</span>
      <input type="search" id="visitHistorySearch" class="form-control" placeholder="Health concern…" autocomplete="off">
    </label>
    <label class="patient-triage-history__field">
      <span class="patient-triage-history__field-label">Status</span>
      <select id="visitHistoryStatus" class="form-control">
        <option value="all">All statuses</option>
        <option value="booked">Visit booked</option>
        <option value="completed">Visit completed</option>
        <option value="emergency">Emergency</option>
        <option value="open">Not completed yet</option>
      </select>
    </label>
    <label class="patient-triage-history__field">
      <span class="patient-triage-history__field-label">Final decision</span>
      <select id="visitHistoryDecision" class="form-control">
        <option value="all">All decisions</option>
        <option value="emergency">Emergency</option>
        <option value="urgent">Urgent</option>
        <option value="non_urgent">Non-Urgent</option>
      </select>
    </label>
    <label class="patient-triage-history__field">
      <span class="patient-triage-history__field-label">Date</span>
      <select id="visitHistoryDate" class="form-control">
        <option value="all">All dates</option>
        <option value="7">Last 7 days</option>
        <option value="30">Last 30 days</option>
        <option value="year">This year</option>
      </select>
    </label>
    <div class="patient-triage-history__filter-meta">
      <p class="patient-triage-history__count" id="visitHistoryCount" aria-live="polite"></p>
      <button type="button" class="patient-triage-history__clear" id="visitHistoryClear" hidden>Clear filters</button>
    </div>
  </div>
  <?php endif; ?>
  <div class="mc-table-wrap">
    <table class="mc-table" id="visitHistoryTable">
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
        <?php else: foreach ($triage_history as $t):
            $statusKey = mc_patient_visit_status_filter_key($t);
            $decisionKey = function_exists('triage_doctor_final_key') ? triage_doctor_final_key($t) : '';
            $assessedYmd = !empty($t['assessed_at']) ? date('Y-m-d', strtotime((string) $t['assessed_at'])) : '';
          ?>
          <tr
            data-concern="<?= htmlspecialchars(strtolower((string) ($t['chief_complaint'] ?? ''))) ?>"
            data-status="<?= htmlspecialchars($statusKey) ?>"
            data-decision="<?= htmlspecialchars($decisionKey) ?>"
            data-date="<?= htmlspecialchars($assessedYmd) ?>"
          >
            <td data-label="Date" class="patient-triage-history__date"><?= $assessedYmd !== '' ? date('M j, Y', strtotime($assessedYmd)) : '—' ?></td>
            <td data-label="Health concern" class="triage-symptoms-cell patient-triage-history__concern-cell">
              <div class="patient-triage-history__concern"><?= htmlspecialchars($t['chief_complaint'] ?? '—') ?></div>
              <?php mc_render_triage_assessment_stack($t, false); ?>
            </td>
            <td data-label="Status" class="patient-triage-history__status">
              <span class="badge-risk <?= mc_patient_visit_status_class($t) ?>"><?= mc_patient_visit_status_label($t, $pdo ?? null, (int) ($uid ?? 0)) ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
          <tr class="patient-triage-history__none" id="visitHistoryNone" hidden>
            <td colspan="3"><div class="mc-table-empty"><p>No visits match these filters.</p></div></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=care-tips" class="patient-triage-care-link">
  <span class="patient-triage-care-link__text">Approved care tips</span>
  <span class="patient-triage-care-link__meta">My Health · Care tips</span>
  <svg class="patient-triage-care-link__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
</a>
