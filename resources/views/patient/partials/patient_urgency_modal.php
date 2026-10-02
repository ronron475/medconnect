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
    <h2 class="mc-urgency-modal__title" id="mcPatientUrgencyTitle">Regular Check-up Recommended</h2>
    <label class="mc-urgency-lang" for="mcPatientUrgencyLang">
      <span class="mc-urgency-lang__label" data-i18n="language">Language</span>
      <select id="mcPatientUrgencyLang" class="mc-urgency-lang__select" aria-label="Language" data-mc-native-select="1">
        <option value="en">English</option>
        <option value="hil">Hiligaynon</option>
        <option value="fil">Tagalog</option>
      </select>
    </label>
    <p class="mc-urgency-modal__message" id="mcPatientUrgencyMessage">AI Assessment: NON-URGENT</p>
    <p id="mcPatientUrgencyContinue" class="mc-urgency-modal__continue" hidden role="status"></p>
    <p id="mcPatientUrgencySafety" class="mc-urgency-modal__safety" hidden></p>
    <ul class="mc-urgency-modal__steps" id="mcPatientUrgencySteps" hidden></ul>

    <div id="mcPatientUrgencyFacility" class="mc-urgency-facility" hidden>
      <p class="mc-urgency-facility__heading" id="mcPatientUrgencyFacilityHeading">NEAREST HEALTH FACILITY</p>
      <p class="mc-urgency-facility__status" id="mcPatientUrgencyFacilityStatus" hidden></p>
      <div id="mcPatientUrgencyFacilityCard" class="mc-urgency-facility__card" hidden>
        <strong id="mcPatientUrgencyFacilityName"></strong>
        <span id="mcPatientUrgencyFacilityType" class="mc-urgency-facility__type"></span>
        <p id="mcPatientUrgencyFacilityAddress"></p>
        <p id="mcPatientUrgencyFacilityDistance" class="mc-urgency-facility__distance"></p>
        <p id="mcPatientUrgencyFacilityContact" class="mc-urgency-facility__contact"></p>
        <p id="mcPatientUrgencyFacilityOpen" class="mc-urgency-facility__open"></p>
      </div>
      <ul id="mcPatientUrgencyFacilityDirectory" class="mc-urgency-facility__directory" hidden></ul>
    </div>

    <div id="mcPatientUrgencySlots" class="mc-urgency-slots" hidden>
      <p class="mc-urgency-slots__heading" data-i18n="slots_heading">Doctors available today</p>
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
      <button type="button" class="mc-urgency-modal__btn mc-urgency-modal__btn--ghost" id="mcPatientUrgencyContinueConsult" data-mc-urgency-close hidden>
        Continue Consultation
      </button>
    </div>
  </div>
</div>

<div
  id="mcPatientBookConfirm"
  class="mc-urgency-modal mc-book-confirm"
  hidden
  role="dialog"
  aria-modal="true"
  aria-labelledby="mcPatientBookConfirmTitle"
  aria-describedby="mcPatientBookConfirmMessage"
>
  <div class="mc-urgency-modal__backdrop" data-mc-book-confirm-cancel></div>
  <div class="mc-urgency-modal__card mc-book-confirm__card" role="document">
    <div class="mc-urgency-modal__icon mc-book-confirm__icon" aria-hidden="true">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="4" width="18" height="17" rx="2"/>
        <line x1="16" y1="2" x2="16" y2="6"/>
        <line x1="8" y1="2" x2="8" y2="6"/>
        <line x1="3" y1="9" x2="21" y2="9"/>
        <path d="M10 12.5v5l4.5-2.5z"/>
      </svg>
    </div>
    <h2 class="mc-urgency-modal__title" id="mcPatientBookConfirmTitle">Confirm Video Consultation</h2>
    <div class="mc-book-confirm__details">
      <strong class="mc-book-confirm__doctor" id="mcPatientBookConfirmDoctor"></strong>
      <span class="mc-book-confirm__type" id="mcPatientBookConfirmType">Video Consultation</span>
      <span class="mc-book-confirm__time" id="mcPatientBookConfirmTime"></span>
    </div>
    <p class="mc-urgency-modal__message" id="mcPatientBookConfirmMessage">Are you sure you want to book this video consultation?</p>
    <div class="mc-urgency-modal__actions">
      <button type="button" class="mc-urgency-modal__btn mc-urgency-modal__btn--ghost" id="mcPatientBookConfirmCancel" data-mc-book-confirm-cancel>Cancel</button>
      <button type="button" class="mc-urgency-modal__btn mc-urgency-modal__btn--primary" id="mcPatientBookConfirmOk">Confirm Booking</button>
    </div>
  </div>
</div>
