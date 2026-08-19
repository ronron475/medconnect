<?php
/**
 * Dashboard card: Chief Complaint (all patients).
 *
 * Expects: $registration_chief_complaint, $show_dashboard_care_tips_section (optional),
 *          $chief_complaint_locked, $chief_complaint_source (optional).
 */
$registration_chief_complaint = trim((string) ($registration_chief_complaint ?? ''));
$chief_complaint_locked = isset($chief_complaint_locked)
    ? (bool) $chief_complaint_locked
    : ($registration_chief_complaint !== '');
$chief_complaint_source = trim((string) ($chief_complaint_source ?? ''));
$chief_complaint_source_label = patient_portal_complaint_source_label($chief_complaint_source);
$show_care_tips_context = !empty($show_dashboard_care_tips_section);
$is_new_consultation_flow = !$chief_complaint_locked
    && empty($active_consultation)
    && isset($pdo, $uid)
    && (
        (function_exists('patient_portal_has_completed_visit') && patient_portal_has_completed_visit($pdo, (int) $uid))
        || (function_exists('patient_portal_has_stale_or_finished_consultation') && patient_portal_has_stale_or_finished_consultation($pdo, (int) $uid))
    );
$card_title = $is_new_consultation_flow ? 'Start New Consultation' : 'Primary Complaint';
$card_lead = $is_new_consultation_flow
    ? 'Share your primary complaint to start a new consultation.'
    : ($show_care_tips_context
        ? 'Describe your primary complaint; your doctor can approve self-care tips after you submit.'
        : 'Share your primary complaint to start triage.');
$submit_label = $show_care_tips_context && !$is_new_consultation_flow
    ? 'Submit for doctor review'
    : 'Submit patient complaint';
$submit_kind = ($show_care_tips_context && !$is_new_consultation_flow) ? 'review' : 'complaint';
$placeholder = $chief_complaint_locked
    ? 'Your submitted primary complaint…'
    : 'Describe your primary complaint...';
?>
<section
  class="pdash-card pdash-card--complaint pdash-care"
  id="pdashChiefComplaint"
  aria-labelledby="pdashChiefComplaintTitle"
>
  <div class="pdash-care__top">
    <div class="pdash-care__title-wrap">
      <span class="pdash-care__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/></svg>
      </span>
      <div>
        <h2 class="pdash-card__title pdash-care__title" id="pdashChiefComplaintTitle"><?= htmlspecialchars($card_title) ?></h2>
        <p class="pdash-care__lead"><?= htmlspecialchars($card_lead) ?></p>
      </div>
    </div>
  </div>

  <?php if ($show_care_tips_context): ?>
  <ol class="pdash-care-steps pdash-care-steps--idle" aria-label="Care tips progress">
    <li class="pdash-care-steps__item is-current" aria-current="step">
      <span class="pdash-care-steps__dot" aria-hidden="true">1</span>
      <span class="pdash-care-steps__label">Primary complaint</span>
    </li>
    <li class="pdash-care-steps__item">
      <span class="pdash-care-steps__dot" aria-hidden="true">2</span>
      <span class="pdash-care-steps__label">Doctor reviews</span>
    </li>
    <li class="pdash-care-steps__item">
      <span class="pdash-care-steps__dot" aria-hidden="true">3</span>
      <span class="pdash-care-steps__label">Care tips ready</span>
    </li>
  </ol>
  <?php endif; ?>

  <form id="pdashSymptomsReviewForm" class="pdash-review-form" novalidate>
    <label class="form-label pdash-care-form__label" for="pdashSymptomsComplaint">
      Primary Complaint
      <?php if ($chief_complaint_locked): ?>
      <span class="pdash-care-form__lock-badge"><?= htmlspecialchars(ucfirst($chief_complaint_source_label)) ?></span>
      <?php endif; ?>
    </label>
    <textarea
      id="pdashSymptomsComplaint"
      name="chief_complaint"
      class="form-control pdash-care-form__input<?= $chief_complaint_locked ? ' pdash-care-form__input--locked' : '' ?>"
      rows="3"
      maxlength="500"
      placeholder="<?= htmlspecialchars($placeholder) ?>"
      <?= $chief_complaint_locked ? 'readonly aria-readonly="true"' : 'required' ?>
    ><?= htmlspecialchars($registration_chief_complaint) ?></textarea>
    <p class="pdash-care-form__hint">
      <?php if ($chief_complaint_locked): ?>
      This primary complaint is already on file and will be reviewed by your doctor. It cannot be changed while this consultation is still active.
      <?php elseif ($is_new_consultation_flow): ?>
      Enter a <strong>new</strong> primary complaint for this consultation. Your previous complaints stay saved in My Sessions and will not be reused.
      <?php else: ?>
      Describe your primary complaint. At least a short sentence helps your care team understand your case faster.
      <?php endif; ?>
    </p>

    <div id="pdashSymptomsReviewAlert" class="patient-triage-alert" role="alert" hidden></div>
    <?php if (!$chief_complaint_locked): ?>
    <button type="submit" class="pdash-btn pdash-btn--primary pdash-care-form__submit" id="pdashSymptomsReviewSubmit" data-submit-kind="<?= htmlspecialchars($submit_kind) ?>">
      <?= htmlspecialchars($submit_label) ?>
    </button>
    <?php endif; ?>
  </form>

  <?php if ($show_care_tips_context): ?>
  <details class="pdash-care-how">
    <summary>How this works</summary>
    <ul>
      <li>AI suggests self-care steps; a licensed doctor must approve before you see them.</li>
      <li>A doctor is assigned automatically—you’ll see their name after you submit.</li>
      <li>Track progress here and under <strong>My Health → Care tips</strong>.</li>
    </ul>
  </details>
  <?php endif; ?>
</section>
