<?php
/**
 * Book consultation + visit history (AI runs silently — not shown to patients).
 * Expects: $active_consultation, $booking_providers, $booking_today_ymd, $booking_today_label,
 *          $triage_history, $default_complaint (optional)
 */
require_once __DIR__ . '/triage_helpers.php';

$default_complaint = trim((string) ($default_complaint ?? ''));
?>
<h2 class="text-h2 mb-md">Book Consultation</h2>
<p class="text-sm text-muted" style="margin-top:-8px;margin-bottom:16px;">
  Your health concern was already reviewed when you registered. Choose a provider and time slot below to complete your booking.
</p>

<?php if (!empty($active_consultation)): ?>
<div class="patient-triage-alert patient-triage-alert--warning is-visible" style="margin-bottom: 16px;">
  <?php if (($active_consultation['status'] ?? '') === 'in_consultation'): ?>
    You currently have a consultation in progress. A new slot cannot be booked until that visit is completed.
  <?php elseif (!empty($booking_blocked_future)): ?>
    You already have an appointment scheduled<?= $booking_future_label !== '' ? ' for ' . htmlspecialchars($booking_future_label) : '' ?>.
    Cancel or complete that visit before booking another slot today.
  <?php else: ?>
    You already have an open appointment<?= !empty($active_consultation['consult_date']) ? ' on ' . htmlspecialchars(date('M j, Y', strtotime($active_consultation['consult_date']))) : '' ?>.
    Submitting here will update it to your newly selected slot for today.
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($booking_providers)): ?>
<div class="patient-triage-alert patient-triage-alert--error is-visible" style="margin-bottom: 16px;">
  No providers are available for booking right now. Please contact the health office.
</div>
<?php endif; ?>

<div class="mc-card patient-triage-form">
  <h3 class="text-h3 mb-md">Schedule Your Visit</h3>
  <div id="triageFormAlert" class="patient-triage-alert" role="alert"></div>
  <form id="patientTriageForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <div class="form-group">
      <?php $has_complaint = $default_complaint !== ''; ?>
      <label class="form-label" for="chief_complaint">
        Health concern<?= $has_complaint ? ' <span class="text-muted">(from registration)</span>' : '' ?>
      </label>
      <textarea
        id="chief_complaint"
        name="chief_complaint"
        class="form-control"
        rows="<?= $has_complaint ? 2 : 3 ?>"
        placeholder="<?= $has_complaint ? 'Your registered health concern…' : 'Briefly describe why you need this visit…' ?>"
        maxlength="500"
        <?= $has_complaint ? 'readonly' : 'required' ?>
      ><?= htmlspecialchars($default_complaint) ?></textarea>
      <?php if ($has_complaint): ?>
      <p class="text-xs text-muted" style="margin-top:6px;">Already on file from registration. Contact the health office if this needs to be updated.</p>
      <?php endif; ?>
    </div>

    <div class="form-group" style="margin-top: 20px;">
      <label class="form-label" for="booking_provider">Choose provider</label>
      <select id="booking_provider" name="provider_id" class="form-control" required>
        <option value="">Select a provider…</option>
        <?php foreach ($booking_providers as $provider): ?>
        <option value="<?= (int) $provider['id'] ?>"><?= htmlspecialchars($provider['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

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

    <button type="submit" class="mc-btn mc-btn--primary" id="patientTriageSubmit" style="width:100%;max-width:320px;">Book Appointment</button>
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

<h3 class="text-h3 mb-md">Visit History</h3>
<div class="mc-card" style="padding: 0; overflow: hidden;">
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
              <span class="badge-risk <?= mc_patient_visit_status_class($t) ?>"><?= mc_patient_visit_status_label($t) ?></span>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
