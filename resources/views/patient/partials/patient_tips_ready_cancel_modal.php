<?php
/**
 * Popup when care tips are approved and patient already has a booked video visit.
 */
$sessionsUrl = (defined('ASSET_BASE') ? ASSET_BASE : '') . '/views/patient/consultations.php';
$careTipsUrl = (defined('ASSET_BASE') ? ASSET_BASE : '') . '/views/patient/my_health.php?tab=care-tips';
?>
<div
  id="mcTipsReadyCancelModal"
  class="mc-tips-ready-modal"
  hidden
  role="dialog"
  aria-modal="true"
  aria-labelledby="mcTipsReadyCancelTitle"
  aria-describedby="mcTipsReadyCancelMessage"
>
  <div class="mc-tips-ready-modal__backdrop" data-mc-tips-ready-dismiss></div>
  <div class="mc-tips-ready-modal__card" role="document">
    <div class="mc-tips-ready-modal__icon" aria-hidden="true">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
        <polyline points="22 4 12 14.01 9 11.01"/>
      </svg>
    </div>
    <p class="mc-tips-ready-modal__eyebrow">Care tips ready</p>
    <h2 class="mc-tips-ready-modal__title" id="mcTipsReadyCancelTitle">Your doctor approved your tips</h2>
    <p class="mc-tips-ready-modal__message" id="mcTipsReadyCancelMessage">
      You already have a video visit booked. If you only need the written tips now, you can cancel the visit so the doctor’s time slot opens for other patients.
    </p>
    <p class="mc-tips-ready-modal__visit" id="mcTipsReadyCancelVisit" hidden></p>
    <div class="mc-tips-ready-modal__actions">
      <button type="button" class="mc-tips-ready-modal__btn mc-tips-ready-modal__btn--danger" id="mcTipsReadyCancelBtn">
        Cancel video visit
      </button>
      <button type="button" class="mc-tips-ready-modal__btn mc-tips-ready-modal__btn--primary" id="mcTipsReadyKeepBtn" data-mc-tips-ready-dismiss>
        Keep my visit
      </button>
      <a href="<?= htmlspecialchars($careTipsUrl) ?>" class="mc-tips-ready-modal__link" id="mcTipsReadyViewTips">
        View care tips
      </a>
      <a href="<?= htmlspecialchars($sessionsUrl) ?>" class="mc-tips-ready-modal__link" hidden id="mcTipsReadySessionsLink">
        My Sessions
      </a>
    </div>
  </div>
</div>
