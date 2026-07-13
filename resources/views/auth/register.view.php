<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>medConnect — Create Patient Account</title>
  <?php
    if (!defined('BASE_PATH')) {
        $d = __DIR__;
        while ($d !== dirname($d)) {
            if (is_file($d . '/mc_load.php')) {
                require_once $d . '/mc_load.php';
                break;
            }
            $d = dirname($d);
        }
    }
    $b = ASSET_BASE;
  ?>
  <link rel="icon" type="image/png" href="<?= $b ?>/assets/img/medcon_logo.png" />
  <link rel="shortcut icon" type="image/png" href="<?= $b ?>/assets/img/medcon_logo.png" />
  <link rel="apple-touch-icon" href="<?= $b ?>/assets/img/medcon_logo.png" />
  <link rel="stylesheet" href="<?= $b ?>/assets/css/style.css?v=20260702a" />
  <link rel="stylesheet" href="<?= $b ?>/assets/css/register.css?v=20260708nlp2" />
  <link rel="stylesheet" href="<?= $b ?>/assets/css/responsive.css" />
  <?php require_once dirname(__DIR__) . '/components/loader.php'; mc_loader_assets(); ?>
</head>
<body class="reg-body">

<?php mc_render_loader_boot(['status' => 'Loading registration…']); ?>

<?php
  $regHeroBg = $reg_hero['bg_image'] ?? ($b . '/assets/img/cho-hero-bg.jpg');
?>

<div class="reg-landing-bg" aria-hidden="true">
  <div class="reg-landing-bg__media" style="background-image:url('<?= htmlspecialchars($regHeroBg, ENT_QUOTES) ?>')"></div>
  <div class="reg-landing-bg__overlay"></div>
</div>

<main class="reg-page">
  <div class="reg-wrapper">

    <header class="registration-header">
      <button type="button" class="btn-nav-back" id="btn-back-to-signin">&#8592; Back to Sign In</button>
      <span class="patient-only-badge">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Patient Registration Only
      </span>
      <h1 class="reg-page-title">Create Patient Account</h1>
      <p class="reg-page-sub">Create your secure medConnect patient account to access online consultations and healthcare services.</p>
    </header>

    <!-- STEP INDICATOR -->
    <nav class="step-indicator" id="step-indicator" aria-label="Registration progress">
      <div class="step-item active" id="step-dot-0" aria-current="step">
        <div class="step-dot" aria-hidden="true">1</div>
        <span class="step-label">Email Verification</span>
      </div>
      <div class="step-line" aria-hidden="true"></div>
      <div class="step-item" id="step-dot-1">
        <div class="step-dot" aria-hidden="true">2</div>
        <span class="step-label">Identity &amp; Residency</span>
      </div>
      <div class="step-line" aria-hidden="true"></div>
      <div class="step-item" id="step-dot-2">
        <div class="step-dot" aria-hidden="true">3</div>
        <span class="step-label">Patient Form</span>
      </div>
    </nav>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- STEP 0: Email OTP Verification                         -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div id="step0" class="step-panel">
      <div class="reg-card">
        <div class="card-header">
          <div class="card-icon-wrap" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
          </div>
          <div class="card-header-text">
            <h2 class="card-title">Step 1 — Email Verification</h2>
            <p class="card-sub">Enter your email address. We'll send a 6-digit OTP to verify it.</p>
          </div>
        </div>

        <div class="alert" id="otp-alert" role="alert" aria-live="polite"></div>

        <!-- Email entry -->
        <div id="otp-email-panel">
          <div class="form-group">
            <label for="otp-email">Email Address <span class="req">*</span></label>
            <div class="input-wrap">
              <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span>
              <input type="email" id="otp-email" placeholder="your.email@example.com" autocomplete="email" />
            </div>
            <span class="field-error" id="otp-email-error" role="alert"></span>
          </div>
          <button type="button" class="btn-submit" id="btn-send-otp" style="width:100%;margin-top:8px">
            <span id="send-otp-btn-text">Send OTP</span>
            <span id="send-otp-spinner" class="btn-spinner" hidden></span>
          </button>
        </div>

        <!-- OTP entry (shown after email sent) -->
        <div id="otp-code-panel" hidden>
          <p class="otp-sent-note" id="otp-sent-note"></p>
          <div class="form-group">
            <label for="otp-input">Enter 6-Digit OTP <span class="req">*</span></label>
            <div class="input-wrap">
              <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
              <input type="text" id="otp-input" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code" style="letter-spacing:6px;font-size:20px;font-weight:600;text-align:center" />
            </div>
            <span class="field-error" id="otp-input-error" role="alert"></span>
          </div>
          <div style="display:flex;gap:10px;margin-top:8px">
            <button type="button" class="btn-back-step" id="btn-change-email" style="flex:0 0 auto">Change Email</button>
            <button type="button" class="btn-submit" id="btn-verify-otp" style="flex:1">
              <span id="verify-otp-btn-text">Verify OTP</span>
              <span id="verify-otp-spinner" class="btn-spinner" hidden></span>
            </button>
          </div>
          <p style="margin-top:12px;font-size:13px;color:#6b7280;text-align:center">
            Didn't receive it? <button type="button" id="btn-resend-otp" style="background:none;border:none;color:#0d9488;cursor:pointer;font-size:13px;padding:0;text-decoration:underline">Resend OTP</button>
            <span id="resend-countdown" style="color:#6b7280;font-size:13px"></span>
          </p>
        </div>

      </div>
    </div>
    <!-- STEP 1: Identity & Residency Verification              -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div id="step1" class="step-panel" hidden>
      <div class="reg-card">
        <div class="card-header">
          <div class="card-icon-wrap" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </div>
          <div class="card-header-text">
            <h2 class="card-title">Step 2 — Identity &amp; Residency</h2>
            <p class="card-sub">Upload your Philippine National ID for digital verification, review the extracted details, and confirm Bago City residency.</p>
          </div>
        </div>

        <div class="alert" id="step1-alert" role="alert" aria-live="polite"></div>

        <form id="step1-form" novalidate autocomplete="off">

          <!-- National ID Verification -->
          <div class="form-section kyc-section" id="section-id-verification">
            <div class="form-section-title">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              National ID Verification
            </div>

            <div class="ocr-section" id="ocr-section">
              <div class="ocr-section-header">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                <span>Upload National ID <span class="req">*</span></span>
                <span class="ocr-badge" id="ocr-badge" hidden></span>
              </div>
              <p class="ocr-desc">Upload a clear photo of the <strong>front</strong> of your Philippine National ID. We will securely read your details and auto-fill the form. You can edit any field before continuing.</p>

              <input type="file" id="national-id-image" name="national_id_image" accept=".jpg,.jpeg,.png,.pdf" style="display:none" aria-label="Upload National ID image" />

              <div class="ocr-upload-area" id="ocr-upload-area" role="button" tabindex="0" aria-label="Upload Philippine National ID">
                <div class="ocr-upload-placeholder" id="ocr-placeholder">
                  <div class="ocr-upload-illus" aria-hidden="true">
                    <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><circle cx="8" cy="11" r="2"/><path d="M14 9h5"/><path d="M14 13h5"/><path d="M6 16h4"/></svg>
                  </div>
                  <span class="ocr-upload-title">Upload your Philippine National ID</span>
                  <ul class="ocr-upload-reqs">
                    <li>Front side only</li>
                    <li>JPG, PNG or PDF</li>
                    <li>Maximum file size: 5 MB</li>
                    <li>Ensure the image is clear and well-lit</li>
                    <li>Avoid blurry or cropped photos</li>
                  </ul>
                  <span class="ocr-upload-hint">Drag &amp; drop here, or use Choose File below</span>
                </div>
                <div class="ocr-upload-success" id="ocr-upload-success" hidden>
                  <div class="ocr-upload-success-icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="8 12 11 15 16 9"/></svg>
                  </div>
                  <span class="ocr-upload-success-title">National ID Uploaded Successfully</span>
                  <span class="ocr-upload-success-file" id="ocr-upload-success-file"></span>
                </div>
              </div>

              <label for="national-id-image" class="btn-ocr-browse" id="btn-ocr-browse">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                Choose File
              </label>

              <div class="ocr-preview-wrap" id="ocr-preview-wrap" hidden>
                <div class="ocr-preview-label">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                  Preview of uploaded National ID
                </div>
                <img id="ocr-preview" class="ocr-preview-img" src="" alt="National ID preview" />
                <div class="ocr-pdf-indicator" id="ocr-pdf-indicator" hidden>
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                  <div><span class="ocr-pdf-badge">PDF</span><span class="ocr-pdf-name" id="ocr-pdf-name"></span></div>
                </div>
              </div>
              <div class="ocr-filename" id="ocr-filename">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                <span id="ocr-filename-text"></span>
              </div>

              <!-- Live OCR processing status -->
              <div class="ocr-status-panel" id="ocr-status-panel" hidden aria-live="polite">
                <div class="ocr-status-panel__head">
                  <span class="ocr-status-spinner" id="ocr-status-spinner" aria-hidden="true"></span>
                  <strong id="ocr-status-panel-title">Processing your National ID</strong>
                </div>
                <ul class="ocr-status-steps" id="ocr-status-steps">
                  <li class="ocr-status-step" data-step="uploaded"><span class="ocr-status-step__icon" aria-hidden="true"></span><span>National ID uploaded successfully.</span></li>
                  <li class="ocr-status-step" data-step="reading"><span class="ocr-status-step__icon" aria-hidden="true"></span><span>Reading your National ID...</span></li>
                  <li class="ocr-status-step" data-step="extracting"><span class="ocr-status-step__icon" aria-hidden="true"></span><span>Extracting personal information...</span></li>
                  <li class="ocr-status-step" data-step="validating"><span class="ocr-status-step__icon" aria-hidden="true"></span><span>Validating residency...</span></li>
                  <li class="ocr-status-step" data-step="done"><span class="ocr-status-step__icon" aria-hidden="true"></span><span>OCR completed successfully.</span></li>
                </ul>
                <div class="ocr-progress" id="ocr-progress" hidden>
                  <div class="ocr-progress-bar"><div class="ocr-progress-fill" id="ocr-progress-fill"></div></div>
                  <span class="ocr-progress-text" id="ocr-progress-text">Reading National ID...</span>
                </div>
              </div>

              <!-- OCR extraction summary -->
              <div class="ocr-summary-card" id="ocr-summary-card" hidden>
                <button type="button" class="ocr-summary-card__toggle" id="ocr-summary-toggle" aria-expanded="true">
                  <span class="ocr-summary-card__title">OCR Extraction Summary</span>
                  <svg class="ocr-summary-card__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="ocr-summary-card__body" id="ocr-summary-body">
                  <p class="ocr-summary-card__stats" id="ocr-summary-stats"></p>
                  <p class="ocr-summary-card__hint">Please verify all information before continuing.</p>
                </div>
              </div>

              <!-- Friendly OCR error / recovery -->
              <div class="ocr-error-card" id="ocr-error-card" hidden role="alert">
                <div class="ocr-error-card__icon" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                </div>
                <div class="ocr-error-card__content">
                  <strong>We couldn’t accurately read your National ID.</strong>
                  <p id="ocr-error-message">Please upload a clearer image or manually complete the missing fields.</p>
                  <div class="ocr-error-card__actions">
                    <button type="button" class="btn-ocr-retry" id="btn-ocr-error-reupload">Upload Another ID</button>
                    <button type="button" class="btn-ocr-manual" id="btn-ocr-manual-entry">Continue with Manual Entry</button>
                  </div>
                </div>
              </div>

              <div class="ocr-extract-preview" id="ocr-extract-preview" hidden>
                <div class="ocr-extract-preview-title">Extracted information</div>
                <ul class="ocr-extract-list" id="ocr-extract-list"></ul>
              </div>

              <div class="ocr-actions" id="ocr-actions" hidden>
                <button type="button" class="btn-ocr-scan" id="btn-ocr-scan">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><rect width="7" height="5" x="7" y="7" rx="1"/><rect width="7" height="5" x="7" y="12" rx="1"/></svg>
                  <span id="ocr-btn-text">Verify Residency</span>
                  <span id="ocr-spinner" class="spinner" hidden aria-hidden="true"></span>
                </button>
                <button type="button" class="btn-ocr-retry" id="btn-ocr-retry" hidden>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
                  Re-read ID
                </button>
                <button type="button" class="btn-ocr-clear" id="btn-ocr-clear" aria-label="Remove uploaded image">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
                  Remove
                </button>
              </div>
              <div class="ocr-status" id="ocr-status" hidden></div>
              <div class="ocr-result-box" id="ocr-result-box" hidden>
                <div class="ocr-result-header" id="ocr-result-header"></div>
                <ul class="ocr-check-list" id="ocr-check-list"></ul>
              </div>
            </div><!-- /ocr-section -->
          </div>

          <!-- Personal Information -->
          <div class="form-section kyc-section" id="section-personal-info">
            <div class="form-section-title">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Personal Information
            </div>

            <div class="ocr-review-notice" id="ocr-review-notice" hidden>
              <div class="ocr-review-notice__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
              </div>
              <div class="ocr-review-notice__body">
                <strong>Review Your Information</strong>
                <p>The information below was automatically extracted from your uploaded National ID using OCR technology.</p>
                <p>Please carefully review every field before continuing. If any information was extracted incorrectly, you may edit it manually.</p>
                <p>Only continue once all information matches your government-issued ID.</p>
              </div>
            </div>

            <div class="form-row form-row-3">
              <div class="form-group" data-ocr-field="first-name">
                <label for="first-name">First Name <span class="req">*</span> <span class="ocr-field-badge" id="badge-first-name" hidden></span></label>
                <div class="input-wrap">
                  <input type="text" id="first-name" name="first_name" placeholder="Waiting for OCR extraction..." autocomplete="off" readonly class="ocr-gated" />
                </div>
                <span class="field-error" id="first-name-error" role="alert"></span>
              </div>
              <div class="form-group" data-ocr-field="middle-name">
                <label for="middle-name">Middle Name <span class="opt">(optional)</span> <span class="ocr-field-badge" id="badge-middle-name" hidden></span></label>
                <div class="input-wrap">
                  <input type="text" id="middle-name" name="middle_name" placeholder="Waiting for OCR extraction..." autocomplete="off" readonly class="ocr-gated" />
                </div>
              </div>
              <div class="form-group" data-ocr-field="last-name">
                <label for="last-name">Last Name <span class="req">*</span> <span class="ocr-field-badge" id="badge-last-name" hidden></span></label>
                <div class="input-wrap">
                  <input type="text" id="last-name" name="last_name" placeholder="Waiting for OCR extraction..." autocomplete="off" readonly class="ocr-gated" />
                </div>
                <span class="field-error" id="last-name-error" role="alert"></span>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group" data-ocr-field="dob">
                <label for="dob">Date of Birth <span class="req">*</span> <span class="ocr-field-badge" id="badge-dob" hidden></span></label>
                <div class="input-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg></span>
                  <input type="date" id="dob" name="date_of_birth" autocomplete="off" readonly class="ocr-gated" />
                </div>
                <span class="field-error" id="dob-error" role="alert"></span>
              </div>
              <div class="form-group">
                <label for="age">Age <span class="auto-label">(auto-computed)</span></label>
                <div class="input-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
                  <input type="number" id="age" name="age" placeholder="—" readonly tabindex="-1" class="readonly-field" />
                </div>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="gender">Gender <span class="req">*</span></label>
                <div class="input-wrap select-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 8l4-4m0 0h-4m4 0v4"/><path d="M8 16l-4 4m0 0h4m-4 0v-4"/></svg></span>
                  <select id="gender" name="gender">
                    <option value="">Select gender</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                  </select>
                </div>
                <span class="field-error" id="gender-error" role="alert"></span>
              </div>
              <div class="form-group">
                <label for="civil-status">Civil Status <span class="req">*</span></label>
                <div class="input-wrap select-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                  <select id="civil-status" name="civil_status">
                    <option value="">Select civil status</option>
                    <option value="Single">Single</option>
                    <option value="Married">Married</option>
                    <option value="Widowed">Widowed</option>
                    <option value="Separated">Separated</option>
                    <option value="Annulled">Annulled</option>
                  </select>
                </div>
                <span class="field-error" id="civil-status-error" role="alert"></span>
              </div>
            </div>
            <div class="form-group form-group-full" style="margin-top:4px" data-ocr-field="national-id">
              <label for="national-id">National ID Number <span class="req">*</span> <span class="auto-label">(auto-filled from ID)</span> <span class="ocr-field-badge" id="badge-national-id" hidden></span></label>
              <div class="input-wrap">
                <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg></span>
                <input type="text" id="national-id" name="national_id" placeholder="Waiting for OCR extraction..." autocomplete="off" readonly class="ocr-gated" />
              </div>
              <span class="field-error" id="national-id-error" role="alert"></span>
            </div>
          </div><!-- /personal -->

          <!-- Address Information -->
          <div class="form-section kyc-section" id="section-address-info">
            <div class="form-section-title">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
              Address Information
            </div>
            <div class="address-section form-group-full">
              <div class="address-grid">
                <div class="form-group">
                  <label for="region">Region <span class="req">*</span></label>
                  <div class="input-wrap select-wrap">
                    <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg></span>
                    <select id="region" name="region_code"><option value="" disabled selected>Choose Region</option></select>
                  </div>
                  <input type="hidden" id="region-text" name="region" />
                  <span class="field-error" id="region-error" role="alert"></span>
                </div>
                <div class="form-group">
                  <label for="province">Province <span class="req">*</span></label>
                  <div class="input-wrap select-wrap">
                    <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></span>
                    <select id="province" name="province_code" disabled><option value="" disabled selected>Choose Province</option></select>
                  </div>
                  <input type="hidden" id="province-text" name="province" />
                  <span class="field-error" id="province-error" role="alert"></span>
                </div>
                <div class="form-group">
                  <label for="city">City / Municipality <span class="req">*</span></label>
                  <div class="input-wrap select-wrap">
                    <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></span>
                    <select id="city" name="city_code" disabled><option value="" disabled selected>Choose City / Municipality</option></select>
                  </div>
                  <input type="hidden" id="city-text" name="city_municipality" />
                  <span class="field-error" id="city-error" role="alert"></span>
                </div>
                <div class="form-group">
                  <label for="barangay">Barangay <span class="req">*</span></label>
                  <div class="input-wrap select-wrap">
                    <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
                    <select id="barangay" name="barangay_code" disabled><option value="" disabled selected>Choose Barangay</option></select>
                  </div>
                  <input type="hidden" id="barangay-text" name="barangay" />
                  <span class="field-error" id="barangay-error" role="alert"></span>
                </div>
              </div>
              <div class="address-lock-notice" id="address-lock-notice" hidden>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Address locked — OCR confirmed Bago City residency.
              </div>

              <div class="form-group form-group-full" style="margin-top:14px" data-ocr-field="street-address">
                <label for="street-address">Street / House Address <span class="ocr-field-badge" id="badge-street-address" hidden></span></label>
                <div class="input-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></span>
                  <input type="text" id="street-address" name="street_address" placeholder="Waiting for OCR extraction..." autocomplete="off" readonly class="ocr-gated" />
                </div>
              </div>

              <p class="gis-barangay-note" role="note">
                Health map location is assigned automatically from your selected barangay — no GPS pin needed.
              </p>

              <div id="non-bago-notice" class="non-bago-notice" hidden role="alert">
                <div class="non-bago-notice__icon" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
                </div>
                <div>
                  <strong>MedConnect registration is only for Bago City residents.</strong>
                  <p>Your address is outside Bago City. You cannot create a patient account here. Please find the nearest hospital in your area for registration and healthcare services.</p>
                  <button type="button" class="non-bago-notice__btn" id="btn-open-referral">Find Nearest Hospital</button>
                </div>
              </div>
            </div>
          </div><!-- /address -->

          <!-- Confirmation -->
          <div class="form-section kyc-section kyc-section--confirm" id="section-confirmation">
            <div class="form-section-title">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              Confirmation
            </div>

            <label class="ocr-confirm-check" for="ocr-info-confirm">
              <input type="checkbox" id="ocr-info-confirm" name="ocr_info_confirm" />
              <span>I confirm that the information above matches my government-issued National ID and that all details are accurate.</span>
            </label>

            <div class="step1-cta">
              <button type="button" class="btn-submit btn-proceed" id="btn-proceed" disabled>
                <span id="proceed-btn-text">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  Create Patient Account
                </span>
              </button>
              <p class="step1-cta-hint" id="step1-cta-hint">Upload your National ID to auto-fill the form, complete any missing fields, confirm the details, then create your account.</p>
            </div>
          </div>

        </form>
      </div><!-- /reg-card step1 -->
    </div><!-- /#step1 -->

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- STEP 2: Patient Form (zigzag layout)                   -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div id="step2" class="step-panel" hidden>
      <div class="reg-card">
        <div class="card-header">
          <div class="card-icon-wrap" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          </div>
          <div class="card-header-text">
            <h2 class="card-title">Step 3 — Patient Form</h2>
            <p class="card-sub">Complete your account, contact, and basic medical profile. Bago City residency confirmed.</p>
          </div>
          <span class="bago-confirmed-badge">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            Bago City Verified
          </span>
        </div>

        <div class="alert" id="step2-alert" role="alert" aria-live="polite"></div>

        <form id="step2-form" class="patient-intake-form" novalidate>
        <!-- Hidden fields carrying Step 1 data -->
        <input type="hidden" id="h-email" name="email" />
        <input type="hidden" id="h-first-name" name="first_name" />
        <input type="hidden" id="h-middle-name" name="middle_name" />
        <input type="hidden" id="h-last-name" name="last_name" />
        <input type="hidden" id="h-dob" name="date_of_birth" />
        <input type="hidden" id="h-age" name="age" />
        <input type="hidden" id="h-gender" name="gender" />
        <input type="hidden" id="h-civil-status" name="civil_status" />
        <input type="hidden" id="h-region" name="region" />
        <input type="hidden" id="h-province" name="province" />
        <input type="hidden" id="h-city" name="city_municipality" />
        <input type="hidden" id="h-barangay" name="barangay" />
        <input type="hidden" id="h-street-address" name="street_address" />
        <input type="hidden" id="h-national-id" name="national_id" />
        <input type="hidden" id="h-national-id-image" name="national_id_image" />

        <div class="intake-sections">

          <!-- Account Security -->
          <section class="intake-card" id="section-account-security" aria-labelledby="intake-account-title">
            <div class="intake-card__head">
              <div class="intake-card__icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </div>
              <div>
                <h3 class="intake-card__title" id="intake-account-title">Account Security</h3>
                <p class="intake-card__sub">Create a secure password for your MedConnect account.</p>
              </div>
            </div>
            <div class="intake-card__body">
              <div class="form-group form-group-full">
                <label for="email-display">Email Address</label>
                <div class="input-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span>
                  <input type="email" id="email-display" placeholder="" readonly class="readonly-verified" />
                </div>
                <span class="field-verified-note">&#10003; Verified via OTP</span>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label for="reg-password">Password <span class="req">*</span></label>
                  <div class="input-wrap">
                    <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                    <input type="password" id="reg-password" name="reg_password" placeholder="Create a strong password" autocomplete="new-password" />
                    <button type="button" class="toggle-pwd" id="toggle-reg-pwd" aria-label="Show password">
                      <svg id="reg-eye-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                  </div>
                  <span class="field-error" id="reg-password-error" role="alert"></span>
                  <div class="pwd-strength-wrap" id="pwd-strength-wrap" hidden>
                    <div class="pwd-strength-bar">
                      <div class="pwd-strength-fill" id="pwd-strength-fill"></div>
                    </div>
                    <span class="pwd-strength-label" id="pwd-strength-label"></span>
                  </div>
                  <ul class="pwd-checklist" id="pwd-checklist" hidden>
                    <li id="pc-len">12+ characters</li>
                    <li id="pc-upper">Uppercase letter</li>
                    <li id="pc-lower">Lowercase letter</li>
                    <li id="pc-num">Number</li>
                    <li id="pc-special">Special character (!@#$%^&amp;*)</li>
                  </ul>
                </div>
                <div class="form-group">
                  <label for="reg-confirm-password">Confirm Password <span class="req">*</span></label>
                  <div class="input-wrap">
                    <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                    <input type="password" id="reg-confirm-password" name="reg_confirm_password" placeholder="Confirm your password" autocomplete="new-password" />
                    <button type="button" class="toggle-pwd" id="toggle-reg-confirm-pwd" aria-label="Show confirm password">
                      <svg id="reg-confirm-eye-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                  </div>
                  <span class="field-error" id="reg-confirm-password-error" role="alert"></span>
                  <span class="pwd-match-hint" id="pwd-match-hint" hidden></span>
                </div>
              </div>
            </div>
          </section>

          <!-- Contact Information -->
          <section class="intake-card" id="section-contact-info" aria-labelledby="intake-contact-title">
            <div class="intake-card__head">
              <div class="intake-card__icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              </div>
              <div>
                <h3 class="intake-card__title" id="intake-contact-title">Contact Information</h3>
                <p class="intake-card__sub">How can healthcare staff reach you?</p>
              </div>
            </div>
            <div class="intake-card__body">
              <div class="form-group form-group-full intake-field-narrow">
                <label for="contact-number">Contact Number <span class="req">*</span></label>
                <div class="input-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                  <input type="tel" id="contact-number" name="contact_number" placeholder="e.g. 09171234567" autocomplete="tel" />
                </div>
                <span class="field-error" id="contact-number-error" role="alert"></span>
              </div>
            </div>
          </section>

          <!-- Current Health Concern (Chief Complaint) -->
          <section class="intake-card intake-card--complaint" id="section-chief-complaint" aria-labelledby="intake-complaint-title">
            <div class="intake-card__head">
              <div class="intake-card__icon intake-card__icon--amber" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
              </div>
              <div>
                <h3 class="intake-card__title" id="intake-complaint-title">Current Health Concern (Chief Complaint)</h3>
                <p class="intake-card__sub">Tell us why you are requesting a consultation today. After you submit, our triage system will securely review your symptoms in the background to guide the next step.</p>
              </div>
            </div>
            <div class="intake-card__body">
              <div class="form-group form-group-full">
                <label for="chief-complaint">Chief Complaint <span class="req">*</span></label>
                <div class="input-wrap">
                  <textarea id="chief-complaint" name="chief_complaint" maxlength="500" rows="4" class="textarea-field textarea-field--tall" placeholder="Example: I have had a fever, sore throat, and cough for the past two days." required></textarea>
                </div>
                <div class="intake-field-meta">
                  <p class="intake-helper-text">Describe your main symptoms, when they started, and any important details. Your healthcare provider will use this information during your consultation.</p>
                  <span class="char-counter" id="chief-complaint-count" aria-live="polite">0/500</span>
                </div>
                <span class="field-error" id="chief-complaint-error" role="alert"></span>
              </div>
            </div>
          </section>

          <!-- Basic Medical Information -->
          <section class="intake-card" id="section-medical-info" aria-labelledby="intake-medical-title">
            <div class="intake-card__head">
              <div class="intake-card__icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
              </div>
              <div>
                <h3 class="intake-card__title" id="intake-medical-title">Basic Medical Information</h3>
                <p class="intake-card__sub">These health details help healthcare providers better understand your medical history before your consultation.</p>
              </div>
            </div>
            <div class="intake-card__body">
              <div class="intake-subblock">
                <div class="form-group intake-field-narrow">
                  <label for="blood-type">Blood Type <span class="req">*</span></label>
                  <div class="input-wrap select-wrap">
                    <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a7 7 0 0 1 7 7c0 5-7 13-7 13S5 14 5 9a7 7 0 0 1 7-7z"/></svg></span>
                    <select id="blood-type" name="blood_type">
                      <option value="">Select blood type</option>
                      <option value="A+">A+</option><option value="A-">A-</option>
                      <option value="B+">B+</option><option value="B-">B-</option>
                      <option value="AB+">AB+</option><option value="AB-">AB-</option>
                      <option value="O+">O+</option><option value="O-">O-</option>
                      <option value="Unknown">I don't know my blood type</option>
                    </select>
                  </div>
                  <span class="field-error" id="blood-type-error" role="alert"></span>
                </div>
              </div>

              <div class="intake-subblock">
                <fieldset class="allergy-fieldset">
                  <legend class="allergy-legend">Do you have any known allergies?</legend>
                  <div class="allergy-choice" role="radiogroup" aria-label="Known allergies">
                    <label class="allergy-choice__option" for="allergy-yes">
                      <input type="radio" name="allergy_choice" id="allergy-yes" value="yes" />
                      <span>Yes</span>
                    </label>
                    <label class="allergy-choice__option" for="allergy-no">
                      <input type="radio" name="allergy_choice" id="allergy-no" value="no" checked />
                      <span>No</span>
                    </label>
                  </div>
                  <div class="allergy-details conditional-details" id="allergy-details" hidden>
                    <div class="form-group form-group-full">
                      <label for="allergies">Please specify your allergies</label>
                      <div class="input-wrap">
                        <textarea id="allergies" name="allergies" placeholder="Example: Penicillin, Peanuts, Shellfish, Latex" rows="3" class="textarea-field"></textarea>
                      </div>
                      <span class="field-error" id="allergies-error" role="alert"></span>
                    </div>
                  </div>
                </fieldset>
              </div>

              <div class="intake-subblock">
                <fieldset class="allergy-fieldset">
                  <legend class="allergy-legend">Are you currently taking any maintenance medications?</legend>
                  <div class="allergy-choice" role="radiogroup" aria-label="Maintenance medications">
                    <label class="allergy-choice__option" for="meds-yes">
                      <input type="radio" name="meds_choice" id="meds-yes" value="yes" />
                      <span>Yes</span>
                    </label>
                    <label class="allergy-choice__option" for="meds-no">
                      <input type="radio" name="meds_choice" id="meds-no" value="no" checked />
                      <span>No</span>
                    </label>
                  </div>
                  <div class="allergy-details conditional-details" id="meds-details" hidden>
                    <div class="form-group form-group-full">
                      <label for="current-medications">Please list your maintenance medication(s)</label>
                      <div class="input-wrap">
                        <textarea id="current-medications" name="current_medications" placeholder="Example: Metformin 500 mg twice daily, Losartan 50 mg once daily" rows="3" class="textarea-field"></textarea>
                      </div>
                      <span class="field-error" id="current-medications-error" role="alert"></span>
                    </div>
                  </div>
                </fieldset>
              </div>

              <div class="intake-subblock">
                <fieldset class="allergy-fieldset">
                  <legend class="allergy-legend">Have you been diagnosed with any long-term medical conditions?</legend>
                  <div class="allergy-choice" role="radiogroup" aria-label="Existing medical conditions">
                    <label class="allergy-choice__option" for="conditions-yes">
                      <input type="radio" name="conditions_choice" id="conditions-yes" value="yes" />
                      <span>Yes</span>
                    </label>
                    <label class="allergy-choice__option" for="conditions-no">
                      <input type="radio" name="conditions_choice" id="conditions-no" value="no" checked />
                      <span>No</span>
                    </label>
                  </div>
                  <div class="allergy-details conditional-details" id="conditions-details" hidden>
                    <div class="form-group form-group-full">
                      <label for="existing-conditions">Please specify your medical condition(s)</label>
                      <div class="input-wrap">
                        <textarea id="existing-conditions" name="existing_conditions" placeholder="Example: Hypertension, Diabetes, Asthma, Heart Disease" rows="3" class="textarea-field"></textarea>
                      </div>
                      <span class="field-error" id="existing-conditions-error" role="alert"></span>
                    </div>
                  </div>
                </fieldset>
              </div>
            </div>
          </section>

          <!-- Privacy Consent -->
          <section class="intake-card intake-card--privacy" id="section-privacy-consent" aria-labelledby="intake-privacy-title">
            <div class="intake-card__head">
              <div class="intake-card__icon intake-card__icon--green" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </div>
              <div>
                <h3 class="intake-card__title" id="intake-privacy-title">Privacy Consent</h3>
                <p class="intake-card__sub">Required under the Data Privacy Act of 2012.</p>
              </div>
            </div>
            <div class="intake-card__body">
              <div class="consent-box">
                <p class="consent-text">Under <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>, City Health Office of Bago City collects and processes your personal and health information solely for purpose of providing healthcare services. Your data will be stored securely, accessed only by authorized personnel, and will not be shared with third parties without your consent except as required by law.</p>
                <label class="consent-label" for="consent-checkbox">
                  <input type="checkbox" id="consent-checkbox" name="consent_given" value="1" />
                  <span class="consent-check-text">I have read and understood above. I voluntarily give my consent to City Health Office of Bago City to collect, store, and process my personal and health information in accordance with RA 10173 (Data Privacy Act of 2012).</span>
                </label>
                <span class="field-error" id="consent-error" role="alert"></span>
              </div>
            </div>
          </section>

        </div><!-- /intake-sections -->

        <div class="step2-actions">
          <button type="button" class="btn-back-step" id="btn-back-step">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            Back
          </button>
          <button type="submit" class="btn-submit" id="reg-submit" disabled>
            <span id="reg-btn-text">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Submit Registration
            </span>
            <span id="reg-spinner" class="spinner" hidden aria-hidden="true"></span>
          </button>
        </div>
        <p class="step2-submit-hint" id="step2-submit-hint">Please accept the privacy consent to enable submission.</p>

        </form>

        <p class="card-footer-link">Already have an account? <a href="#" onclick="document.getElementById('btn-back-to-signin').click();return false;">Sign in here</a></p>
        <div class="emergency-note" role="note">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
          For emergency cases, please proceed to the nearest healthcare facility immediately.
        </div>

      </div><!-- /.reg-card step2 -->
    </div><!-- /#step2 -->

  </div><!-- /.reg-wrapper -->
</main>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
  window.APP_BASE = <?= json_encode($b) ?>;
  window.CSRF_TOKEN = <?= json_encode((string) ($_SESSION['csrf_token'] ?? '')) ?>;
  window.RECAPTCHA_SITE_KEY = <?= json_encode((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) ?>;
  window.RECAPTCHA_VERSION = <?= json_encode((string) (defined('RECAPTCHA_VERSION') ? RECAPTCHA_VERSION : 'v3')) ?>;
</script>
<?php if (!empty((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) && !empty((string) (defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : ''))): ?>
  <?php if (strtolower((string) (defined('RECAPTCHA_VERSION') ? RECAPTCHA_VERSION : 'v3')) === 'v2'): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php else: ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) ?>"></script>
  <?php endif; ?>
<?php endif; ?>
<script src="<?= $b ?>/assets/js/ph-address-autofill.js?v=20260703h"></script>
<script src="<?= $b ?>/assets/js/ocr-national-id.js?v=20260708kyc"></script>
<script src="<?= $b ?>/assets/js/register-nlp-analysis.js?v=20260708nlp2"></script>
<script src="<?= $b ?>/assets/js/register.js?v=20260708nlp2"></script>

<!-- Silent NLP loading overlay (patient-facing; no technical AI output) -->
<?php
mc_render_loader_panel([
    'id' => 'reg-nlp-overlay',
    'title' => 'Analyzing your health concern…',
    'sub' => 'Please wait while our AI-powered triage system securely reviews your symptoms and prepares your consultation.',
    'progress' => true,
    'steps_id' => 'reg-nlp-overlay-steps',
]);
?>

<!-- Post-registration: Non-urgent success -->
<div id="reg-outcome-success" class="reg-outcome-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="reg-outcome-success-title">
  <div class="reg-outcome-modal__card reg-outcome-modal__card--success">
    <div class="reg-outcome-modal__icon" aria-hidden="true">✓</div>
    <h2 id="reg-outcome-success-title" class="reg-outcome-modal__title">Registration Successful</h2>
    <p class="reg-outcome-modal__msg">Your account has been created successfully.</p>
    <p class="reg-outcome-modal__msg">You may now schedule a consultation at your preferred available date and time.</p>
    <div class="reg-outcome-modal__actions">
      <button type="button" class="reg-outcome-btn reg-outcome-btn--primary" id="reg-outcome-success-go">Continue to Sign In</button>
    </div>
  </div>
</div>

<!-- Post-registration: Urgent -->
<div id="reg-outcome-urgent" class="reg-outcome-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="reg-outcome-urgent-title">
  <div class="reg-outcome-modal__card reg-outcome-modal__card--urgent">
    <div class="reg-outcome-modal__icon reg-outcome-modal__icon--urgent" aria-hidden="true">!</div>
    <h2 id="reg-outcome-urgent-title" class="reg-outcome-modal__title">Urgent Medical Attention Recommended</h2>
    <p class="reg-outcome-modal__msg">Based on the symptoms you provided, your condition may require prompt medical attention.</p>
    <p class="reg-outcome-modal__msg">The earliest available consultation time will be automatically selected for you. After signing in, confirm the suggested slot or choose another available time.</p>
    <div class="reg-outcome-modal__actions">
      <button type="button" class="reg-outcome-btn reg-outcome-btn--primary" id="reg-outcome-urgent-view">View Earliest Appointment</button>
      <button type="button" class="reg-outcome-btn reg-outcome-btn--ghost" id="reg-outcome-urgent-continue">Continue</button>
    </div>
  </div>
</div>

<!-- Post-registration: Emergency (blocking) -->
<div id="reg-outcome-emergency" class="reg-outcome-modal reg-outcome-modal--blocking" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="reg-outcome-emergency-title">
  <div class="reg-outcome-modal__card reg-outcome-modal__card--emergency">
    <div class="reg-outcome-modal__icon reg-outcome-modal__icon--emergency" aria-hidden="true">⚠</div>
    <h2 id="reg-outcome-emergency-title" class="reg-outcome-modal__title">Emergency Symptoms Detected</h2>
    <p class="reg-outcome-modal__msg">Based on the symptoms you entered, your condition may be a medical emergency.</p>
    <p class="reg-outcome-modal__msg">Please seek immediate medical attention at the nearest hospital or emergency department.</p>
    <p class="reg-outcome-modal__msg reg-outcome-modal__msg--strong">Do not wait for an online consultation if you are experiencing severe or worsening symptoms.</p>
    <div class="reg-outcome-modal__actions">
      <button type="button" class="reg-outcome-btn reg-outcome-btn--danger" id="reg-outcome-emergency-hospitals">View Nearby Hospitals</button>
      <a class="reg-outcome-btn reg-outcome-btn--ghost" href="tel:911" id="reg-outcome-emergency-call">Call Emergency Services</a>
      <button type="button" class="reg-outcome-btn reg-outcome-btn--muted" id="reg-outcome-emergency-ack">I Understand</button>
    </div>
  </div>
</div>

<!-- Back to Sign In confirmation modal -->
<div id="backToSigninModal" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(4,12,24,0.80);backdrop-filter:blur(7px);align-items:center;justify-content:center;padding:20px">
  <div style="background:linear-gradient(160deg,#0b1f38,#071525);border:1px solid rgba(45,212,191,0.18);border-radius:18px;padding:36px 30px;max-width:400px;width:100%;box-shadow:0 24px 72px rgba(0,0,0,0.60);text-align:center;animation:regModalIn 0.25s ease">
    <!-- Icon -->
    <div style="width:56px;height:56px;border-radius:50%;background:rgba(239,68,68,0.12);border:1.5px solid rgba(239,68,68,0.28);display:flex;align-items:center;justify-content:center;margin:0 auto 18px">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" x2="9" y1="12" y2="12"/>
      </svg>
    </div>
    <!-- Title -->
    <h3 style="font-size:18px;font-weight:800;color:#fff;margin:0 0 10px">Leave Registration?</h3>
    <!-- Message -->
    <p style="font-size:13.5px;color:rgba(255,255,255,0.58);margin:0 0 26px;line-height:1.65">Are you sure you want to go back to Sign In?<br>Your registration progress will be lost.</p>
    <!-- Buttons -->
    <div style="display:flex;gap:10px;justify-content:center">
      <button id="backToSigninCancel" type="button"
        style="height:42px;padding:0 24px;border-radius:10px;border:1px solid rgba(255,255,255,0.16);background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.82);font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;transition:background 0.2s"
        onmouseover="this.style.background='rgba(255,255,255,0.14)'"
        onmouseout="this.style.background='rgba(255,255,255,0.08)'">
        Cancel
      </button>
      <button id="backToSigninConfirm" type="button"
        style="height:42px;padding:0 24px;border-radius:10px;border:none;background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:opacity 0.2s"
        onmouseover="this.style.opacity='0.88'"
        onmouseout="this.style.opacity='1'">
        Yes, Go Back
      </button>
    </div>
  </div>
</div>

<style>
@keyframes regModalIn {
  from { opacity:0; transform:translateY(18px) scale(0.97); }
  to   { opacity:1; transform:translateY(0)    scale(1);    }
}
</style>

<script>
(function () {
  const modal      = document.getElementById('backToSigninModal');
  const btnOpen    = document.getElementById('btn-back-to-signin');
  const btnCancel  = document.getElementById('backToSigninCancel');
  const btnConfirm = document.getElementById('backToSigninConfirm');
  const signInUrl  = <?= json_encode($b . '/index.php') ?>;

  function openModal()  { modal.style.display = 'flex'; }
  function closeModal() { modal.style.display = 'none'; }

  // Always show modal — user is on the registration page
  if (btnOpen) {
    btnOpen.addEventListener('click', e => {
      e.preventDefault();
      openModal();
    });
  }

  if (btnCancel)  btnCancel.addEventListener('click', closeModal);
  if (btnConfirm) btnConfirm.addEventListener('click', () => { window.location.href = signInUrl; });

  if (modal) {
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  }

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && modal?.style.display === 'flex') closeModal();
  });
})();
</script>

<?php
require_once VIEWS_PATH . '/partials/referral_modal.php';
require_once __DIR__ . '/partials/registration_requirements_modal.php';
?>
</body>
</html>
