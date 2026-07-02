<?php
/**
 * Speed Dial FAB — landing page quick navigation (bottom-right).
 */
?>
<div class="landing-fab" id="landing-fab" data-open="false">
  <div
    class="landing-fab__backdrop"
    id="landing-fab-backdrop"
    aria-hidden="true"
    tabindex="-1"
  ></div>

  <button
    type="button"
    class="landing-fab__toggle"
    id="landing-fab-toggle"
    aria-expanded="false"
    aria-controls="landing-fab-stack"
    aria-label="Open quick menu"
  >
    <span class="landing-fab__bar"></span>
    <span class="landing-fab__bar"></span>
    <span class="landing-fab__bar"></span>
  </button>

  <div
    class="landing-fab__stack landing-fab__stack--hidden"
    id="landing-fab-stack"
    aria-hidden="true"
  >
    <nav class="landing-fab__nav" id="landing-fab-menu" aria-label="Quick menu">
      <button
        type="button"
        class="landing-fab__btn landing-fab__btn--announcements"
        data-fab-modal="announcements"
        aria-label="Open Announcements"
        title="Announcements"
      >
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>
        <span class="landing-fab__tooltip">Announcements</span>
      </button>
      <button
        type="button"
        class="landing-fab__btn landing-fab__btn--services"
        data-fab-modal="services"
        aria-label="Open Services"
        title="Services"
      >
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 7v4"/><path d="M10 11h4"/><path d="M18 8v11a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V8"/><path d="M6 8h12"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
        <span class="landing-fab__tooltip">Services</span>
      </button>
      <button
        type="button"
        class="landing-fab__btn landing-fab__btn--how"
        data-fab-modal="how-it-works"
        aria-label="Open How It Works"
        title="How It Works"
      >
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>
        <span class="landing-fab__tooltip">How It Works</span>
      </button>
      <button
        type="button"
        class="landing-fab__btn landing-fab__btn--contact"
        data-fab-modal="contact"
        aria-label="Open Contact"
        title="Contact"
      >
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        <span class="landing-fab__tooltip">Contact</span>
      </button>
    </nav>
  </div>
</div>
