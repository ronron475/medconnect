<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>medConnect — Create Patient Account</title>
  <link rel="stylesheet" href="../../assets/css/style.css" />
  <link rel="stylesheet" href="../../assets/css/register.css" />
</head>
<body>

<div class="bg-canvas" aria-hidden="true">
  <canvas id="bubble-canvas"></canvas>
</div>

<nav class="navbar" id="navbar">
  <div class="nav-container">
    <a href="../../public/index.php" class="nav-logo">
      <span class="logo-icon" aria-hidden="true">
        <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
          <rect width="30" height="30" rx="9" fill="url(#lg2)"/>
          <path d="M15 8v14M8 15h14" stroke="#fff" stroke-width="2.8" stroke-linecap="round"/>
          <defs><linearGradient id="lg2" x1="0" y1="0" x2="30" y2="30" gradientUnits="userSpaceOnUse"><stop stop-color="#0d9488"/><stop offset="1" stop-color="#0ea5e9"/></linearGradient></defs>
        </svg>
      </span>
      <span class="logo-text">med<span class="logo-accent">Connect</span></span>
    </a>
    <a href="../../public/index.php" class="btn-nav-back">&#8592; Back to Sign In</a>
  </div>
</nav>

<main class="reg-page">
  <div class="reg-wrapper">

    <!-- PAGE HEADER -->
    <div class="reg-page-header">
      <span class="patient-only-badge">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Patient Registration Only
      </span>
      <div class="reg-page-header-text">
        <h1 class="reg-page-title">Create Patient Account</h1>
        <p class="reg-page-sub">City Health Office of Bago City &mdash; medConnect</p>
      </div>
    </div>

    <!-- STEP INDICATOR -->
    <div class="step-indicator" id="step-indicator">
      <div class="step-item active" id="step-dot-0">
        <div class="step-dot">1</div>
        <span class="step-label">Email Verification</span>
      </div>
      <div class="step-line"></div>
      <div class="step-item" id="step-dot-1">
        <div class="step-dot">2</div>
        <span class="step-label">Identity &amp; Residency</span>
      </div>
      <div class="step-line"></div>
      <div class="step-item" id="step-dot-2">
        <div class="step-dot">3</div>
        <span class="step-label">Patient Form</span>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- STEP 0: Email OTP Verification                         -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div id="step0" class="step-panel">
      <div class="reg-card">
        <div class="card-header">
          <div class="card-icon-wrap" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
          </div>
          <div>
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
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </div>
          <div>
            <h2 class="card-title">Step 1 — Identity &amp; Residency</h2>
            <p class="card-sub">Fill in your basic details and verify your National ID to confirm Bago City residency.</p>
          </div>
        </div>

        <div class="alert" id="step1-alert" role="alert" aria-live="polite"></div>

        <form id="step1-form" novalidate>

          <!-- Personal Information -->
          <div class="form-section">
            <div class="form-section-title">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Personal Information
            </div>
            <div class="form-row form-row-3">
              <div class="form-group">
                <label for="first-name">First Name <span class="req">*</span></label>
                <div class="input-wrap">
                  <input type="text" id="first-name" name="first_name" placeholder="Juan" autocomplete="given-name" />
                </div>
                <span class="field-error" id="first-name-error" role="alert"></span>
              </div>
              <div class="form-group">
                <label for="middle-name">Middle Name <span class="opt">(optional)</span></label>
                <div class="input-wrap">
                  <input type="text" id="middle-name" name="middle_name" placeholder="Santos" autocomplete="additional-name" />
                </div>
              </div>
              <div class="form-group">
                <label for="last-name">Last Name <span class="req">*</span></label>
                <div class="input-wrap">
                  <input type="text" id="last-name" name="last_name" placeholder="Dela Cruz" autocomplete="family-name" />
                </div>
                <span class="field-error" id="last-name-error" role="alert"></span>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="dob">Date of Birth <span class="req">*</span></label>
                <div class="input-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg></span>
                  <input type="date" id="dob" name="date_of_birth" />
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
          </div><!-- /personal -->

          <!-- Address -->
          <div class="form-section">
            <div class="form-section-title">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
              Address
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
            </div>
          </div><!-- /address -->

          <!-- National ID Verification -->
          <div class="form-section">
            <div class="form-section-title">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              National ID Verification
            </div>

            <!-- National ID number input (needed for OCR matching) -->
            <div class="form-group form-group-full" style="margin-bottom:14px">
              <label for="national-id">National ID Number <span class="req">*</span></label>
              <div class="input-wrap">
                <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg></span>
                <input type="text" id="national-id" name="national_id" placeholder="e.g. 1234-5678-9012-3456" autocomplete="off" />
              </div>
              <span class="field-error" id="national-id-error" role="alert"></span>
            </div>

            <div class="ocr-section" id="ocr-section">
              <div class="ocr-section-header">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <span>Upload National ID Image <span class="req">*</span></span>
                <span class="ocr-badge" id="ocr-badge" hidden></span>
              </div>
              <p class="ocr-desc">Upload a clear photo of your National ID. The system will verify your details and confirm Bago City residency.</p>
              <input type="file" id="national-id-image" name="national_id_image" accept=".jpg,.jpeg,.png,.pdf" style="display:none" aria-label="Upload National ID image" />
              <div class="ocr-upload-area" id="ocr-upload-area">
                <div class="ocr-upload-placeholder" id="ocr-placeholder">
                  <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><path d="M12 12v-3m0 0-2 2m2-2 2 2"/><path d="M2 15h20"/></svg>
                  <span>Drag your National ID here</span>
                  <span class="ocr-upload-hint">JPG, PNG, PDF — max 5 MB</span>
                </div>
              </div>
              <label for="national-id-image" class="btn-ocr-browse" id="btn-ocr-browse">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                Choose File
              </label>
              <div class="ocr-preview-wrap" id="ocr-preview-wrap" hidden>
                <div class="ocr-preview-label">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                  Preview of selected National ID
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
              <div class="ocr-actions" id="ocr-actions" hidden>
                <button type="button" class="btn-ocr-scan" id="btn-ocr-scan">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><rect width="7" height="5" x="7" y="7" rx="1"/><rect width="7" height="5" x="7" y="12" rx="1"/></svg>
                  <span id="ocr-btn-text">Verify ID</span>
                  <span id="ocr-spinner" class="spinner" hidden aria-hidden="true"></span>
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
          </div><!-- /verification section -->

          <!-- Create Patient Account button — only enabled after Bago confirmed -->
          <div class="step1-cta">
            <button  click="goStep2()"  type="button" class="btn-submit btn-proceed" id="btn-proceed" disabled>
              <span id="proceed-btn-text">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Create Patient Account
              </span>
            </button>
            <p class="step1-cta-hint" id="step1-cta-hint">Complete National ID verification to enable this button.</p>
          </div>

        </form>
      </div><!-- /reg-card step1 -->
    </div><!-- /#step1 -->

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- STEP 2: Patient Form (zigzag layout)                   -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div id="step2" class="step-panel" hidden>

      <!-- Step 2 header -->
      <div class="step2-header">
        <div class="step2-header-icon" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </div>
        <div>
          <h2 class="step2-title">Patient Form</h2>
          <p class="step2-sub">Complete your health profile. Bago City residency confirmed.</p>
        </div>
        <span class="bago-confirmed-badge">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
          Bago City Verified
        </span>
      </div>

      <div class="alert" id="step2-alert" role="alert" aria-live="polite"></div>

      <form id="step2-form" novalidate>
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
        <input type="hidden" id="h-national-id" name="national_id" />
        <input type="hidden" id="h-national-id-image" name="national_id_image" />

        <!-- SECTION 0: Account Login Information -->
        <div class="form-section">
          <div class="form-section-title">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            Account Login Information
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Email Address</label>
              <div class="input-wrap">
                <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span>
                <input type="email" id="email-display" placeholder="" readonly style="background:#f1f5f9;color:#64748b;cursor:not-allowed" />
              </div>
              <span style="font-size:12px;color:#0d9488">&#10003; Verified via OTP</span>
            </div>
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
            </div>
          </div>
        </div>

        <!-- SINGLE COLUMN PROFESSIONAL LAYOUT -->
        <div class="single-column-layout">

          <!-- SECTION 1: Socio-Economic Information -->
          <div class="form-section">
            <div class="form-section-title">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" x2="12" y1="12" y2="16"/><line x1="10" x2="14" y1="14" y2="14"/></svg>
              Socio-Economic Information
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="employment-status">Employment Status <span class="req">*</span></label>
                <div class="input-wrap select-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></span>
                  <select id="employment-status" name="employment_status">
                    <option value="">Select status</option>
                    <option value="Employed">Employed</option>
                    <option value="Self-employed">Self-employed</option>
                    <option value="Unemployed">Unemployed</option>
                    <option value="Student">Student</option>
                    <option value="Retired">Retired</option>
                  </select>
                </div>
                <span class="field-error" id="employment-status-error" role="alert"></span>
              </div>
              <div class="form-group">
                <label for="income-bracket">Monthly Income Bracket <span class="req">*</span></label>
                <div class="input-wrap select-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="1" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                  <select id="income-bracket" name="monthly_income_bracket">
                    <option value="">Select bracket</option>
                    <option value="No income">No income</option>
                    <option value="Below ₱5,000">Below ₱5,000</option>
                    <option value="₱5,000 – ₱9,999">₱5,000 – ₱9,999</option>
                    <option value="₱10,000 – ₱19,999">₱10,000 – ₱19,999</option>
                    <option value="₱20,000 – ₱39,999">₱20,000 – ₱39,999</option>
                    <option value="₱40,000 and above">₱40,000 and above</option>
                  </select>
                </div>
                <span class="field-error" id="income-bracket-error" role="alert"></span>
              </div>
            </div>
          </div>

          <!-- SECTION 2: Contact & Health Information -->
          <div class="form-section">
            <div class="form-section-title">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              Contact &amp; Health Information
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="contact-number">Contact Number <span class="req">*</span></label>
                <div class="input-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.8a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                  <input type="tel" id="contact-number" name="contact_number" placeholder="e.g. 09171234567" autocomplete="tel" />
                </div>
                <span class="field-error" id="contact-number-error" role="alert"></span>
              </div>
              <div class="form-group">
                <label for="philhealth-status">PhilHealth Status <span class="req">*</span></label>
                <div class="input-wrap select-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                  <select id="philhealth-status" name="philhealth_status">
                    <option value="">Select status</option>
                    <option value="Member">Member</option>
                    <option value="Dependent">Dependent</option>
                    <option value="None">None / Not a Member</option>
                  </select>
                </div>
                <span class="field-error" id="philhealth-status-error" role="alert"></span>
              </div>
            </div>
          </div>

          <!-- SECTION 3: Medical History -->
          <div class="form-section">
            <div class="form-section-title">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
              Medical History
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="blood-type">Blood Type <span class="req">*</span></label>
                <div class="input-wrap select-wrap">
                  <span class="input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a7 7 0 0 1 7 7c0 5-7 13-7 13S5 14 5 9a7 7 0 0 1 7-7z"/></svg></span>
                  <select id="blood-type" name="blood_type">
                    <option value="">Select blood type</option>
                    <option value="A+">A+</option><option value="A-">A-</option>
                    <option value="B+">B+</option><option value="B-">B-</option>
                    <option value="AB+">AB+</option><option value="AB-">AB-</option>
                    <option value="O+">O+</option><option value="O-">O-</option>
                    <option value="Unknown">Unknown</option>
                  </select>
                </div>
                <span class="field-error" id="blood-type-error" role="alert"></span>
              </div>
            </div>
            <div class="form-group">
              <label for="existing-conditions">Existing Medical Conditions <span class="opt">(optional)</span></label>
              <div class="input-wrap">
                <textarea id="existing-conditions" name="existing_conditions" placeholder="e.g. Hypertension, Diabetes Type 2 (leave blank if none)" rows="2" class="textarea-field"></textarea>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="allergies">Known Allergies <span class="opt">(optional)</span></label>
                <div class="input-wrap">
                  <textarea id="allergies" name="allergies" placeholder="e.g. Penicillin, Shellfish (leave blank if none)" rows="2" class="textarea-field"></textarea>
                </div>
              </div>
              <div class="form-group">
                <label for="current-medications">Current Medications <span class="opt">(optional)</span></label>
                <div class="input-wrap">
                  <textarea id="current-medications" name="current_medications" placeholder="e.g. Metformin 500mg daily (leave blank if none)" rows="2" class="textarea-field"></textarea>
                </div>
              </div>
            </div>
          </div>

        </div><!-- /single-column-layout -->

        <!-- Consent -->
        <div class="consent-section" style="margin-top:28px">
          <div class="form-section-title">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Data Privacy Consent
          </div>
          <div class="consent-box">
            <p class="consent-text">Under <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>, City Health Office of Bago City collects and processes your personal and health information solely for purpose of providing healthcare services. Your data will be stored securely, accessed only by authorized personnel, and will not be shared with third parties without your consent except as required by law.</p>
            <label class="consent-label" for="consent-checkbox">
              <input type="checkbox" id="consent-checkbox" name="consent_given" value="1" />
              <span class="consent-check-text">I have read and understood above. I voluntarily give my consent to City Health Office of Bago City to collect, store, and process my personal and health information in accordance with RA 10173 (Data Privacy Act of 2012).</span>
            </label>
            <span class="field-error" id="consent-error" role="alert"></span>
          </div>
        </div>

        <!-- Privacy notice -->
        <div class="privacy-notice" role="note">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span>Your information is protected under Data Privacy Act of 2012 and used solely for healthcare purposes by City Health Office of Bago City.</span>
        </div>

        <div class="step2-actions">
          <button type="button" class="btn-back-step" id="btn-back-step">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            Back
          </button>
          <button type="submit" class="btn-submit" id="reg-submit">
            <span id="reg-btn-text">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Submit Registration
            </span>
            <span id="reg-spinner" class="spinner" hidden aria-hidden="true"></span>
          </button>
        </div>

      </form>

      <p class="card-footer-link" style="margin-top:16px">Already have an account? <a href="../../public/index.php">Sign in here</a></p>
      <div class="emergency-note" role="note">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        For emergency cases, please proceed to the nearest healthcare facility immediately.
      </div>

    </div><!-- /#step2 -->

  </div><!-- /.reg-wrapper -->
</main>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="../../assets/js/register.js"></script>
</body>
</html>
