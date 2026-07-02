<?php
// ── Contact form — pre-fill from $pt, flash messages from session ──
$cf_contact  = htmlspecialchars($pt['contact_number']   ?? '');
$cf_email    = htmlspecialchars($pt['email']            ?? '');
$cf_barangay = htmlspecialchars($pt['barangay']         ?? '');
$cf_city     = htmlspecialchars($pt['city_municipality']?? '');

// Flash feedback set by contact_update.controller.php
$cf_success = $_SESSION['contact_success'] ?? null;
$cf_errors  = $_SESSION['contact_errors']  ?? [];
unset($_SESSION['contact_success'], $_SESSION['contact_errors']);

// Per-field error helper
$fe = fn(string $k) => $cf_errors[$k] ?? null;
?>

<div class="cuf-card">

  <!-- ── Header ── -->
  <div class="cuf-header">
    <div class="cuf-header-left">
      <div class="cuf-header-icon">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/>
          <circle cx="12" cy="10" r="3"/>
        </svg>
      </div>
      <div>
        <h2 class="cuf-title">Contact &amp; Address</h2>
        <p class="cuf-sub">Update your contact details and location</p>
      </div>
    </div>
    <span class="cuf-editable-badge">
      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
      </svg>
      Editable
    </span>
  </div>

  <!-- ── Flash messages ── -->
  <?php if ($cf_success): ?>
  <div class="cuf-alert cuf-alert--success" role="alert">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
      <polyline points="22 4 12 14.01 9 11.01"/>
    </svg>
    <?= htmlspecialchars($cf_success) ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($cf_errors) && isset($cf_errors['general'])): ?>
  <div class="cuf-alert cuf-alert--error" role="alert">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10"/>
      <line x1="12" y1="8" x2="12" y2="12"/>
      <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <?= htmlspecialchars($cf_errors['general']) ?>
  </div>
  <?php endif; ?>

  <!-- ── Form ── -->
  <form
    id="contactUpdateForm"
    class="cuf-form"
    method="POST"
    action="<?= ASSET_BASE ?>/app/api/patient/update_contact.php"
    novalidate
  >
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"/>

    <div class="cuf-form-grid">

      <!-- Contact Number -->
      <div class="cuf-field <?= $fe('contact_number') ? 'cuf-field--error' : '' ?>">
        <label class="cuf-label" for="contact_number">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
          </svg>
          Contact Number
          <span class="cuf-required" aria-label="required">*</span>
        </label>
        <div class="cuf-input-wrap">
          <input
            type="tel"
            id="contact_number"
            name="contact_number"
            class="cuf-input"
            value="<?= $cf_contact ?>"
            placeholder="e.g. 09171234567"
            maxlength="15"
            required
            autocomplete="tel"
          />
        </div>
        <?php if ($fe('contact_number')): ?>
          <span class="cuf-field-error"><?= htmlspecialchars($fe('contact_number')) ?></span>
        <?php endif; ?>
      </div>

      <!-- Email Address -->
      <div class="cuf-field <?= $fe('email') ? 'cuf-field--error' : '' ?>">
        <label class="cuf-label" for="email">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
          Email Address
          <span class="cuf-required" aria-label="required">*</span>
        </label>
        <div class="cuf-input-wrap">
          <input
            type="email"
            id="email"
            name="email"
            class="cuf-input"
            value="<?= $cf_email ?>"
            placeholder="e.g. juan@email.com"
            maxlength="180"
            required
            autocomplete="email"
          />
        </div>
        <?php if ($fe('email')): ?>
          <span class="cuf-field-error"><?= htmlspecialchars($fe('email')) ?></span>
        <?php endif; ?>
      </div>

      <!-- Barangay -->
      <div class="cuf-field <?= $fe('barangay') ? 'cuf-field--error' : '' ?>">
        <label class="cuf-label" for="barangay">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
          </svg>
          Barangay
          <span class="cuf-required" aria-label="required">*</span>
        </label>
        <div class="cuf-input-wrap">
          <input
            type="text"
            id="barangay"
            name="barangay"
            class="cuf-input"
            value="<?= $cf_barangay ?>"
            placeholder="e.g. Barangay 1"
            maxlength="120"
            required
            autocomplete="address-level3"
          />
        </div>
        <?php if ($fe('barangay')): ?>
          <span class="cuf-field-error"><?= htmlspecialchars($fe('barangay')) ?></span>
        <?php endif; ?>
      </div>

      <!-- City / Municipality -->
      <div class="cuf-field <?= $fe('city_municipality') ? 'cuf-field--error' : '' ?>">
        <label class="cuf-label" for="city_municipality">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/>
            <circle cx="12" cy="10" r="3"/>
          </svg>
          City / Municipality
        </label>
        <div class="cuf-input-wrap">
          <input
            type="text"
            id="city_municipality"
            name="city_municipality"
            class="cuf-input cuf-input--readonly"
            value="<?= $cf_city ?>"
            placeholder="e.g. Bago City"
            maxlength="120"
            readonly
            title="City is managed by the health office"
          />
          <span class="cuf-input-lock" aria-hidden="true">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
          </span>
        </div>
        <span class="cuf-field-hint">Managed by the City Health Office</span>
      </div>

    </div><!-- /cuf-form-grid -->

    <!-- ── Actions ── -->
    <div class="cuf-actions">
      <button type="submit" class="cuf-btn-submit">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/>
          <polyline points="17 21 17 13 7 13 7 21"/>
          <polyline points="7 3 7 8 15 8"/>
        </svg>
        Save Changes
      </button>
      <button type="reset" class="cuf-btn-reset">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="1 4 1 10 7 10"/>
          <path d="M3.51 15a9 9 0 1 0 .49-3.5"/>
        </svg>
        Reset
      </button>
    </div>

  </form>

  <!-- ── Footer note ── -->
  <div class="cuf-footer">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
    </svg>
    Your information is protected and only accessible to authorized health personnel.
  </div>

</div>
