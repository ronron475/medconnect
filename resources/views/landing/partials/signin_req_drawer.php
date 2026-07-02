<?php
/**
 * Sign-In page only — left FAB + registration requirements drawer.
 * Independent from the landing page FAB (bottom-right).
 */
?>
<div class="signin-req-drawer-root" id="signin-req-drawer-root" aria-hidden="true">
  <div class="signin-req-fab-wrap" aria-hidden="false">
    <button
      type="button"
      class="signin-req-fab"
      id="signin-req-fab"
      aria-expanded="false"
      aria-controls="signin-req-drawer-panel"
      aria-label="Open patient registration requirements"
      title="Registration requirements"
    >
      <svg class="signin-req-fab__icon signin-req-fab__icon--info" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
      <svg class="signin-req-fab__icon signin-req-fab__icon--close" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </button>
  </div>

  <div class="signin-req-drawer" id="signin-req-drawer" hidden>
    <div class="signin-req-drawer__overlay" id="signin-req-drawer-overlay" data-signin-drawer-close tabindex="-1"></div>

    <aside
      class="signin-req-drawer__panel"
      id="signin-req-drawer-panel"
      role="dialog"
      aria-modal="true"
      aria-labelledby="signin-req-drawer-title"
      aria-hidden="true"
    >
      <header class="signin-req-drawer__header">
        <div class="signin-req-drawer__header-main">
          <span class="signin-req-drawer__header-icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </span>
          <div>
            <h2 class="signin-req-drawer__title" id="signin-req-drawer-title">Patient Registration Requirements</h2>
            <p class="signin-req-drawer__subtitle">Review these guidelines before creating your account.</p>
          </div>
        </div>
        <button type="button" class="signin-req-drawer__close" data-signin-drawer-close aria-label="Close requirements drawer">&times;</button>
      </header>

      <div class="signin-req-drawer__body" id="signin-req-drawer-body">
        <!-- Personal Information -->
        <section class="signin-req-drawer__section" aria-labelledby="signin-req-personal-heading">
          <h3 class="signin-req-drawer__section-title" id="signin-req-personal-heading">
            <span class="signin-req-drawer__section-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            Personal Information
          </h3>

          <article class="signin-req-drawer__card">
            <h4 class="signin-req-drawer__card-title">Full Name</h4>
            <ul class="signin-req-drawer__list">
              <li>Use your real legal name as it appears on your government-issued ID.</li>
              <li>Nicknames, aliases, and fake names are not allowed.</li>
              <li>Only letters, spaces, apostrophes ('), periods (.), and hyphens (-) are permitted.</li>
              <li>Minimum 2 characters, maximum 100 characters.</li>
            </ul>
          </article>

          <article class="signin-req-drawer__card">
            <h4 class="signin-req-drawer__card-title">Date of Birth</h4>
            <ul class="signin-req-drawer__list">
              <li>Enter your actual date of birth.</li>
              <li>Must be a valid calendar date.</li>
              <li>Future dates are not allowed.</li>
            </ul>
          </article>

          <article class="signin-req-drawer__card">
            <h4 class="signin-req-drawer__card-title">Gender</h4>
            <ul class="signin-req-drawer__list">
              <li>Gender is required.</li>
              <li>Select your correct gender.</li>
            </ul>
          </article>
        </section>

        <!-- Account Information -->
        <section class="signin-req-drawer__section" aria-labelledby="signin-req-account-heading">
          <h3 class="signin-req-drawer__section-title" id="signin-req-account-heading">
            <span class="signin-req-drawer__section-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            </span>
            Account Information
          </h3>

          <article class="signin-req-drawer__card">
            <h4 class="signin-req-drawer__card-title">Email Address</h4>
            <ul class="signin-req-drawer__list">
              <li>Must be a valid email address.</li>
              <li>Must not already exist.</li>
              <li>Verification code will be sent.</li>
            </ul>
          </article>

          <article class="signin-req-drawer__card">
            <h4 class="signin-req-drawer__card-title">Username</h4>
            <ul class="signin-req-drawer__list">
              <li>5–30 characters.</li>
              <li>Letters, numbers, and underscores (_) only.</li>
              <li>Must be unique.</li>
            </ul>
          </article>
        </section>

        <!-- Password -->
        <section class="signin-req-drawer__section" aria-labelledby="signin-req-password-heading">
          <h3 class="signin-req-drawer__section-title" id="signin-req-password-heading">
            <span class="signin-req-drawer__section-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            Password
          </h3>

          <article class="signin-req-drawer__card signin-req-drawer__card--password">
            <p class="signin-req-drawer__card-intro">Password must contain:</p>
            <ul class="signin-req-drawer__list signin-req-drawer__list--compact">
              <li>At least 8 characters</li>
              <li>One uppercase letter</li>
              <li>One lowercase letter</li>
              <li>One number</li>
              <li>One special character</li>
            </ul>

            <div class="signin-req-drawer__pwd-demo">
              <label for="signin-req-demo-password" class="signin-req-drawer__pwd-label">Try a sample password (preview only)</label>
              <input
                type="password"
                id="signin-req-demo-password"
                class="signin-req-drawer__pwd-input"
                placeholder="Type to preview checklist"
                autocomplete="off"
                aria-describedby="signin-req-pwd-checklist"
              />
              <ul class="signin-req-drawer__pwd-checklist" id="signin-req-pwd-checklist">
                <li id="signin-req-pc-len">8+ characters</li>
                <li id="signin-req-pc-upper">Uppercase letter</li>
                <li id="signin-req-pc-lower">Lowercase letter</li>
                <li id="signin-req-pc-num">Number</li>
                <li id="signin-req-pc-special">Special character (!@#$%^&amp;*)</li>
              </ul>
            </div>
          </article>
        </section>

        <!-- Contact Number -->
        <section class="signin-req-drawer__section" aria-labelledby="signin-req-contact-heading">
          <h3 class="signin-req-drawer__section-title" id="signin-req-contact-heading">
            <span class="signin-req-drawer__section-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </span>
            Contact Number
          </h3>

          <article class="signin-req-drawer__card">
            <ul class="signin-req-drawer__list">
              <li>Philippine mobile number only.</li>
              <li>Must begin with 09.</li>
              <li>Exactly 11 digits.</li>
              <li>Must not already exist.</li>
            </ul>
          </article>
        </section>

        <!-- Address -->
        <section class="signin-req-drawer__section" aria-labelledby="signin-req-address-heading">
          <h3 class="signin-req-drawer__section-title" id="signin-req-address-heading">
            <span class="signin-req-drawer__section-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            </span>
            Address
          </h3>

          <article class="signin-req-drawer__card">
            <p class="signin-req-drawer__card-intro">Required:</p>
            <ul class="signin-req-drawer__list signin-req-drawer__list--compact">
              <li>Province</li>
              <li>City/Municipality</li>
              <li>Barangay</li>
              <li>Complete Residential Address</li>
            </ul>
          </article>
        </section>
      </div>
    </aside>
  </div>
</div>
