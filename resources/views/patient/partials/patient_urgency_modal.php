<?php
/**
 * Patient urgency modal — emergency / urgent / non-urgent after symptom submit or triage.
 */
$asset = defined('ASSET_BASE') ? ASSET_BASE : '';
$bookUrl = $asset . '/views/patient/triage.php';
?>
<div
  id="mcPatientUrgencyModal"
  class="mc-urgency-modal"
  hidden
  role="dialog"
  aria-modal="true"
  aria-labelledby="mcPatientUrgencyTitle"
  aria-describedby="mcPatientUrgencyMessage"
>
  <div class="mc-urgency-modal__backdrop" data-mc-urgency-close></div>
  <div class="mc-urgency-modal__card mc-urgency-modal__card--wide" role="document">
    <div class="mc-urgency-modal__icon" id="mcPatientUrgencyIcon" aria-hidden="true">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/>
        <line x1="12" y1="17" x2="12.01" y2="17"/>
      </svg>
    </div>
    <p class="mc-urgency-modal__eyebrow" id="mcPatientUrgencyEyebrow">NON-URGENT</p>
    <h2 class="mc-urgency-modal__title" id="mcPatientUrgencyTitle">Routine Care Recommended</h2>
    <label class="mc-urgency-lang" for="mcPatientUrgencyLang">
      <span class="mc-urgency-lang__label" data-i18n="language">Language</span>
      <select id="mcPatientUrgencyLang" class="mc-urgency-lang__select" aria-label="Language">
        <option value="en">English</option>
        <option value="fil">Filipino</option>
        <option value="hil">Hiligaynon</option>
      </select>
    </label>
    <p class="mc-urgency-modal__message" id="mcPatientUrgencyMessage">
      Emergency symptoms were detected. Please go to the nearest hospital or emergency department.
      Online self-care tips and teleconsultation are not appropriate for this case.
    </p>
    <p id="mcPatientUrgencyContinue" class="mc-urgency-modal__continue" hidden role="status">
      Please click &ldquo;Submit patient complaint&rdquo; again to continue.
    </p>
    <ul class="mc-urgency-modal__steps" id="mcPatientUrgencySteps">
      <li>Call local emergency services if needed</li>
      <li>Go to the nearest hospital or ER</li>
      <li>Do not wait for online care tips or a video slot</li>
    </ul>

    <div id="mcPatientUrgencySlots" class="mc-urgency-slots" hidden>
      <p class="mc-urgency-slots__heading" data-i18n="slots_heading">Soonest available today</p>
      <div id="mcPatientUrgencySlotsList" class="mc-urgency-slots__list" role="list"></div>
      <p id="mcPatientUrgencySlotsStatus" class="mc-urgency-slots__status" hidden role="status"></p>
    </div>

    <div class="mc-urgency-modal__actions">
      <button type="button" class="mc-urgency-modal__btn mc-urgency-modal__btn--ghost" data-mc-urgency-close data-i18n="i_understand">
        I understand
      </button>
      <a
        id="mcPatientUrgencyPrimary"
        class="mc-urgency-modal__btn mc-urgency-modal__btn--primary"
        href="<?= htmlspecialchars($bookUrl) ?>"
        hidden
        data-i18n="choose_another_time"
      >Choose another time</a>
    </div>
  </div>
</div>
