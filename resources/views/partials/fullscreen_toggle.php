<?php
/**
 * Shared navbar fullscreen / floating-view toggle — Admin, Superadmin, BHW, Patient, Provider.
 * Desktop: browser Fullscreen API. Mobile/tablet: in-page Floating View.
 * Optional: $fullscreen_btn_class (defaults to topbar-icon-btn).
 */
$fsBtnClass = trim((string) ($fullscreen_btn_class ?? 'topbar-icon-btn'));
if ($fsBtnClass === '') {
    $fsBtnClass = 'topbar-icon-btn';
}
?>
<button
  type="button"
  class="<?= htmlspecialchars($fsBtnClass, ENT_QUOTES) ?> mc-fullscreen-btn"
  data-mc-fullscreen-toggle
  title="Enter fullscreen"
  aria-label="Enter fullscreen"
  aria-pressed="false"
>
  <svg data-fs-icon width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
  </svg>
</button>
