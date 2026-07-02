<?php
/**
 * Patient Registration Requirements — informational modal (MedConnect).
 */
?>
<div
  class="reg-req-modal"
  id="regRequirementsModal"
  role="dialog"
  aria-modal="true"
  aria-labelledby="reg-req-modal-title"
  aria-hidden="true"
  hidden
>
  <div class="reg-req-modal__overlay" data-reg-req-close tabindex="-1"></div>

  <div class="reg-req-modal__dialog">
    <header class="reg-req-modal__header">
      <div class="reg-req-modal__header-icon" aria-hidden="true">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <div>
        <h2 class="reg-req-modal__title" id="reg-req-modal-title">Patient Registration Requirements</h2>
        <p class="reg-req-modal__subtitle">Please review these guidelines before creating your medConnect account.</p>
      </div>
      <button type="button" class="reg-req-modal__close-icon" data-reg-req-close aria-label="Close requirements modal">&times;</button>
    </header>

    <div class="reg-req-modal__body">
      <!-- Personal Information -->
      <section class="reg-req-section" aria-labelledby="reg-req-personal-heading">
        <h3 class="reg-req-section__title" id="reg-req-personal-heading">
          <span class="reg-req-section__icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </span>
          Personal Information
        </h3>

        <article class="reg-req-card">
          <h4 class="reg-req-card__title">Full Name</h4>
          <ul class="reg-req-list">
            <li>Use your <strong>real legal name</strong> exactly as it appears on your valid government-issued ID or official documents.</li>
            <li>Nicknames, aliases, fake names, or special symbols are <strong>not allowed</strong>.</li>
            <li>Only letters, spaces, apostrophes ('), periods (.), and hyphens (-) are permitted.</li>
            <li>Minimum of <strong>2 characters</strong> and a maximum of <strong>100 characters</strong>.</li>
          </ul>
        </article>

        <article class="reg-req-card">
          <h4 class="reg-req-card__title">Date of Birth</h4>
          <ul class="reg-req-list">
            <li>Enter your <strong>actual date of birth</strong>.</li>
            <li>Must be a <strong>valid calendar date</strong>.</li>
            <li>Future dates are <strong>not allowed</strong>.</li>
          </ul>
        </article>

        <article class="reg-req-card">
          <h4 class="reg-req-card__title">Gender</h4>
          <ul class="reg-req-list">
            <li><strong>Gender is required.</strong></li>
            <li>Select your correct gender from the available options.</li>
          </ul>
        </article>
      </section>

      <!-- Account Information -->
      <section class="reg-req-section" aria-labelledby="reg-req-account-heading">
        <h3 class="reg-req-section__title" id="reg-req-account-heading">
          <span class="reg-req-section__icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
          </span>
          Account Information
        </h3>

        <article class="reg-req-card">
          <h4 class="reg-req-card__title">Email Address</h4>
          <ul class="reg-req-list">
            <li>Must be a <strong>valid email address</strong>.</li>
            <li>Must <strong>not already be registered</strong>.</li>
            <li>A <strong>verification code</strong> will be sent to your email address.</li>
          </ul>
        </article>

        <article class="reg-req-card">
          <h4 class="reg-req-card__title">Username</h4>
          <ul class="reg-req-list">
            <li>Must contain <strong>5–30 characters</strong>.</li>
            <li>Only letters, numbers, and underscores (_) are allowed.</li>
            <li>Must be <strong>unique</strong>.</li>
          </ul>
        </article>
      </section>

      <!-- Password Requirements -->
      <section class="reg-req-section" aria-labelledby="reg-req-password-heading">
        <h3 class="reg-req-section__title" id="reg-req-password-heading">
          <span class="reg-req-section__icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          </span>
          Password Requirements
        </h3>

        <article class="reg-req-card reg-req-card--password">
          <p class="reg-req-card__intro">Your password must contain:</p>
          <ul class="reg-req-list reg-req-list--compact">
            <li>At least <strong>8 characters</strong></li>
            <li>At least <strong>one uppercase letter</strong></li>
            <li>At least <strong>one lowercase letter</strong></li>
            <li>At least <strong>one number</strong></li>
            <li>At least <strong>one special character</strong></li>
          </ul>

          <div class="reg-req-pwd-demo">
            <label for="reg-req-demo-password" class="reg-req-pwd-demo__label">Try a sample password (preview only)</label>
            <div class="input-wrap">
              <span class="input-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <input
                type="password"
                id="reg-req-demo-password"
                class="reg-req-pwd-demo__input"
                placeholder="Type to preview strength"
                autocomplete="off"
                aria-describedby="reg-req-pwd-strength-label reg-req-pwd-checklist"
              />
            </div>

            <div class="pwd-strength-wrap" id="reg-req-pwd-strength-wrap" hidden>
              <div class="pwd-strength-bar">
                <div class="pwd-strength-fill" id="reg-req-pwd-strength-fill"></div>
              </div>
              <span class="pwd-strength-label" id="reg-req-pwd-strength-label"></span>
            </div>

            <ul class="pwd-checklist" id="reg-req-pwd-checklist" hidden>
              <li id="reg-req-pc-len">8+ characters</li>
              <li id="reg-req-pc-upper">Uppercase letter</li>
              <li id="reg-req-pc-lower">Lowercase letter</li>
              <li id="reg-req-pc-num">Number</li>
              <li id="reg-req-pc-special">Special character (!@#$%^&amp;*)</li>
            </ul>
          </div>
        </article>
      </section>

      <!-- Contact Number -->
      <section class="reg-req-section" aria-labelledby="reg-req-contact-heading">
        <h3 class="reg-req-section__title" id="reg-req-contact-heading">
          <span class="reg-req-section__icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          </span>
          Contact Number
        </h3>

        <article class="reg-req-card">
          <ul class="reg-req-list">
            <li><strong>Philippine mobile numbers only.</strong></li>
            <li>Must start with <strong>09</strong>.</li>
            <li>Must contain exactly <strong>11 digits</strong>.</li>
            <li>Must <strong>not already be registered</strong> in the system.</li>
          </ul>
        </article>
      </section>

      <!-- Address Information -->
      <section class="reg-req-section" aria-labelledby="reg-req-address-heading">
        <h3 class="reg-req-section__title" id="reg-req-address-heading">
          <span class="reg-req-section__icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
          </span>
          Address Information
        </h3>

        <article class="reg-req-card">
          <p class="reg-req-card__intro">The following information is required:</p>
          <ul class="reg-req-list reg-req-list--compact">
            <li>Province</li>
            <li>City / Municipality</li>
            <li>Barangay</li>
            <li>Complete Residential Address</li>
          </ul>
          <p class="reg-req-card__note">Please enter your <strong>current residential address</strong> accurately.</p>
        </article>
      </section>
    </div>

    <footer class="reg-req-modal__footer">
      <button type="button" class="reg-req-btn reg-req-btn--secondary" data-reg-req-close>Close</button>
      <button type="button" class="reg-req-btn reg-req-btn--primary" id="reg-req-understand">I Understand</button>
    </footer>
  </div>
</div>
