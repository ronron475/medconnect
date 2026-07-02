<?php
/**
 * Triage assessment form + history.
 * Expects: $active_consultation, $booking_providers, $booking_today_ymd, $booking_today_label, $triage_history
 */
require_once __DIR__ . '/triage_helpers.php';
?>
<h2 class="text-h2 mb-md">AI-Assisted Medical Assessment</h2>
<p class="text-sm text-muted" style="margin-top:-8px;margin-bottom:16px;">
  Describe symptoms in Hiligaynon, English, Tagalog, or mixed language. The AI engine will translate, analyze severity, and suggest triage guidance.
</p>

<?php if (!empty($active_consultation)): ?>
<div class="patient-triage-alert patient-triage-alert--warning is-visible" style="margin-bottom: 16px;">
  <?php if (($active_consultation['status'] ?? '') === 'in_consultation'): ?>
    You currently have a consultation in progress. Your assessment can still be saved, but a new slot cannot be booked until that visit is completed.
  <?php else: ?>
    You already have an open appointment<?= !empty($active_consultation['consult_date']) ? ' on ' . htmlspecialchars(date('M j, Y', strtotime($active_consultation['consult_date']))) : '' ?>.
    Submitting here will update it to your newly selected slot.
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($booking_providers)): ?>
<div class="patient-triage-alert patient-triage-alert--error is-visible" style="margin-bottom: 16px;">
  No providers are available for booking right now. Please contact the health office.
</div>
<?php endif; ?>

<div class="mc-card patient-triage-form">
  <h3 class="text-h3 mb-md">Submit New Assessment</h3>
  <div id="triageFormAlert" class="patient-triage-alert" role="alert"></div>
  <form id="patientTriageForm" novalidate>
    <div class="form-group">
      <label class="form-label" for="chief_complaint">Chief complaint</label>
      <textarea id="chief_complaint" name="chief_complaint" class="form-control" rows="3" placeholder="e.g. grabe sakit ulo kag ginahilanat, may nagasuka…" maxlength="500"></textarea>
    </div>
    <label class="form-label">Symptoms (select all that apply)</label>
    <div class="symptom-grid">
      <?php
      $symptom_options = ['Fever', 'Cough', 'Headache', 'Chest pain', 'Shortness of breath', 'Sore throat', 'Nausea', 'Rash', 'Mild pain', 'Stomach ache'];
      foreach ($symptom_options as $sym):
      ?>
      <label class="symptom-chip">
        <input type="checkbox" name="symptoms[]" value="<?= htmlspecialchars(strtolower($sym)) ?>">
        <?= htmlspecialchars($sym) ?>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="ai-assessment-wrap">
      <div class="ai-assessment-actions">
        <button type="button" id="btnRunAssessment" class="mc-btn mc-btn--secondary">
          Run AI Assessment
        </button>
      </div>
      <div id="aiAssessmentPanel" class="ai-assessment-panel" aria-live="polite">
        <p class="ai-assessment-empty">Run AI assessment to preview detected symptoms, confidence, and triage level before booking.</p>
      </div>
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

    <button type="submit" class="mc-btn mc-btn--primary" style="width:100%;max-width:320px;">Submit Assessment &amp; Book Slot</button>
  </form>
</div>

<h3 class="text-h3 mb-md">Assessment History</h3>
<div class="mc-card" style="padding: 0; overflow: hidden;">
  <div class="mc-table-wrap">
    <table class="mc-table">
      <thead><tr><th>Assessment Date</th><th>Logged Symptoms</th><th>Risk Level</th><th>Complaint</th></tr></thead>
      <tbody>
        <?php if (empty($triage_history)): ?>
          <tr><td colspan="4"><div class="mc-table-empty"><p>No triage records found.</p></div></td></tr>
        <?php else: foreach ($triage_history as $t):
          $risk_class = mc_triage_risk_class((string)($t['level'] ?? ''));
        ?>
          <tr>
            <td data-label="Date" style="font-weight: 700; color: var(--mc-navy-dark);"><?= !empty($t['assessed_at']) ? date('M j, Y', strtotime($t['assessed_at'])) : '—' ?></td>
            <td data-label="Symptoms" class="triage-symptoms-cell"><?= mc_format_triage_symptoms($t['symptoms'] ?? null) ?></td>
            <td data-label="Risk"><span class="badge-risk <?= $risk_class ?>"><?= mc_triage_level_label((string)($t['level'] ?? ''), $t['urgency_label'] ?? null) ?></span></td>
            <td data-label="Complaint" class="triage-symptoms-cell"><?= htmlspecialchars($t['chief_complaint'] ?? '—') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
