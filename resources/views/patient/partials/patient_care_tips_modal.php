<?php
/**
 * Modal shell for viewing doctor-approved self-care tips (one triage case at a time).
 */
?>
<div
  id="pmhCareTipsModal"
  class="pmh-care-modal"
  hidden
  role="dialog"
  aria-modal="true"
  aria-labelledby="pmhCareTipsModalTitle"
  aria-describedby="pmhCareTipsModalDesc"
>
  <div class="pmh-care-modal__backdrop" data-pmh-care-tips-close tabindex="-1"></div>
  <div class="pmh-care-modal__dialog" role="document">
    <header class="pmh-care-modal__header">
      <div class="pmh-care-modal__header-text">
        <p class="pmh-care-modal__eyebrow">Doctor-reviewed care tips</p>
        <h2 id="pmhCareTipsModalTitle" class="pmh-care-modal__title"></h2>
      </div>
      <button type="button" class="pmh-care-modal__close" data-pmh-care-tips-close aria-label="Close care tips">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </header>

    <div class="pmh-care-modal__content">
      <div class="pmh-care-modal__meta">
        <time class="pmh-care-modal__datetime" id="pmhCareTipsModalDate"></time>
        <span class="pmh-care-modal__status" id="pmhCareTipsModalStatus"></span>
      </div>

      <p class="pmh-care-modal__provider" id="pmhCareTipsModalProvider" hidden></p>

      <p class="pmh-care-modal__approval" id="pmhCareTipsModalDesc">
        These self-care steps were reviewed and approved by your licensed provider before being shared with you.
      </p>

      <ol class="pmh-care-modal__list" id="pmhCareTipsModalList"></ol>
    </div>
  </div>
</div>
