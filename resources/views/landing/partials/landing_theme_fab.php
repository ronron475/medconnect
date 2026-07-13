<?php
/**
 * Floating theme toggle — single-click light/dark (BSIS-style), scroll-revealed after hero.
 */
?>
<div class="landing-theme-fab" id="landing-theme-fab" aria-hidden="true">
  <button
    type="button"
    class="landing-theme-fab__btn"
    id="landing-theme-toggle"
    aria-label="Switch to dark mode"
    aria-pressed="false"
    title="Toggle light / dark mode"
  >
    <span class="landing-theme-fab__icon-slot" aria-hidden="true">
      <svg class="landing-theme-fab__icon landing-theme-fab__icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
      </svg>
      <svg class="landing-theme-fab__icon landing-theme-fab__icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="4"/>
        <line x1="12" y1="2" x2="12" y2="4"/>
        <line x1="12" y1="20" x2="12" y2="22"/>
        <line x1="4.93" y1="4.93" x2="6.34" y2="6.34"/>
        <line x1="17.66" y1="17.66" x2="19.07" y2="19.07"/>
        <line x1="2" y1="12" x2="4" y2="12"/>
        <line x1="20" y1="12" x2="22" y2="12"/>
        <line x1="4.93" y1="19.07" x2="6.34" y2="17.66"/>
        <line x1="17.66" y1="6.34" x2="19.07" y2="4.93"/>
      </svg>
    </span>
  </button>
</div>
