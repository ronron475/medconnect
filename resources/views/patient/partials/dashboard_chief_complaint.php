<?php
/**
 * Dashboard card: Chief Complaint + optional supporting evidence (all patients).
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
$show_evidence_section = false;
$show_care_tips_context = !empty($show_dashboard_care_tips_section);
$is_new_consultation_flow = !$chief_complaint_locked
    && empty($active_consultation)
    && isset($pdo, $uid)
    && (
        (function_exists('patient_portal_has_completed_visit') && patient_portal_has_completed_visit($pdo, (int) $uid))
        || (function_exists('patient_portal_has_stale_or_finished_consultation') && patient_portal_has_stale_or_finished_consultation($pdo, (int) $uid))
    );
$card_title = $is_new_consultation_flow ? 'Start New Consultation' : 'Patient Complaint';
$card_lead = $is_new_consultation_flow
    ? 'Share your current health concern to start a new consultation.'
    : ($show_care_tips_context
        ? 'Describe your concern; your doctor can approve self-care tips after you submit.'
        : 'Share your current health concern to start triage.');
$submit_label = $is_new_consultation_flow
    ? 'Submit patient complaint'
    : ($show_care_tips_context ? 'Submit for doctor review' : 'Submit patient complaint');
$submit_kind = ($show_care_tips_context && !$is_new_consultation_flow) ? 'review' : 'complaint';
$placeholder = $chief_complaint_locked
    ? 'Your submitted health concern…'
    : 'Describe your current health concern...';
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
      <span class="pdash-care-steps__label">Patient complaint</span>
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
      Patient Complaint
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
      This patient complaint is already on file and will be reviewed by your doctor. It cannot be changed while this consultation is still active.
      <?php elseif ($is_new_consultation_flow): ?>
      Enter a <strong>new</strong> health concern for this consultation. Previous complaints remain in My Sessions and are not reused.
      <?php else: ?>
      Describe your health concern. At least a short sentence helps your care team understand your case faster.
      <?php endif; ?>
    </p>

    <div
      class="pdash-care-evidence<?= $show_evidence_section ? '' : ' pdash-care-evidence--collapsed' ?>"
      id="pdashCareEvidenceSection"
      <?= $show_evidence_section ? '' : 'hidden inert aria-hidden="true"' ?>
    >
      <label class="form-label pdash-care-form__label" for="pdashSupportingEvidence">
        <span data-i18n="evidence_label">Supporting Evidence</span> <span class="pdash-care-form__optional" data-i18n="evidence_optional">(optional)</span>
      </label>
      <p class="pdash-care-form__hint pdash-care-evidence__hint" data-i18n="evidence_hint">
        Upload a photo or short video for your doctor to review. This does not affect triage or review priority.
      </p>
      <div class="pdash-care-evidence__upload">
        <input
          type="file"
          id="pdashSupportingEvidence"
          name="supporting_evidence"
          class="pdash-care-evidence__input"
          accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"
          <?= $show_evidence_section ? '' : 'disabled tabindex="-1"' ?>
        />
        <button
          type="button"
          class="pdash-btn pdash-btn--outline pdash-care-evidence__choose"
          id="pdashBtnChooseEvidence"
          data-i18n="evidence_choose"
          <?= $show_evidence_section ? '' : 'disabled' ?>
        >
          Choose photo or video
        </button>
        <span class="pdash-care-evidence__filename" id="pdashEvidenceFilename" hidden></span>
        <button type="button" class="pdash-care-evidence__remove" id="pdashBtnRemoveEvidence" hidden aria-label="Remove supporting evidence" data-i18n="evidence_remove">
          Remove
        </button>
      </div>
      <div class="pdash-care-evidence__preview" id="pdashEvidencePreview" hidden></div>
      <p class="pdash-care-form__hint" data-i18n="evidence_limits">Photos up to 5 MB. Videos up to 25 MB (MP4 or WebM).</p>
    </div>

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
