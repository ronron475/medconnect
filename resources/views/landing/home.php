<?php

/** Landing page view — rendered by index.php */

$asset = ASSET_BASE;

?>

<!DOCTYPE html>

<html lang="en">

<head>

  <meta charset="UTF-8" />

  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>

  <title>medConnect Online Video Call Consultation and AI-Powered Triage System </title>

  <link rel="icon" type="image/png" href="<?= $asset ?>/assets/img/medcon_logo.png" />

  <link rel="shortcut icon" type="image/png" href="<?= $asset ?>/assets/img/medcon_logo.png" />

  <?php require_once VIEWS_PATH . '/partials/theme_init.php'; ?>

  <link rel="stylesheet" href="<?= $asset ?>/assets/css/style.css?v=20260702b" />

  <link rel="stylesheet" href="<?= $asset ?>/assets/css/responsive.css" />

  <?php require_once dirname(__DIR__) . '/components/loader.php'; mc_loader_assets(); ?>
  <?php $mcModalCssVer = (int) @filemtime(ASSETS_PATH . '/css/mc-modal-system.css'); ?>
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/mc-modal-system.css?v=<?= $mcModalCssVer ?>" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/hero-illustration.css?v=4" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/announcement-modal.css" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/location-modal.css?v=1" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-announcements.css?v=23" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-nav.css?v=3.2" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-fab.css?v=12" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-fab-modals.css?v=2" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/signin-req-drawer.css?v=9" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/signin-card-polish.css?v=2" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/hero-signin-panel.css?v=11" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-scroll-animations.css?v=4" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-hero-search.css?v=4" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-about-team.css?v=7" />
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-responsive.css?v=3" />
  <?php $landingThemeFabCssVer = (int) @filemtime(ASSETS_PATH . '/css/landing-theme-fab.css'); ?>
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-theme-fab.css?v=<?= $landingThemeFabCssVer ?>" />

  <script>

    window.ASSET_BASE = "<?= ASSET_BASE ?>";
    window.RECAPTCHA_SITE_KEY = <?= json_encode((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) ?>;
    window.RECAPTCHA_VERSION = <?= json_encode((string) (defined('RECAPTCHA_VERSION') ? RECAPTCHA_VERSION : 'v3')) ?>;

  </script>

</head>

<body class="landing-page<?= empty($landing_hero['animation']) ? ' landing-page--no-hero-anim' : '' ?>">

<script>
  document.documentElement.classList.add('lsa-ready');
  <?php if (!empty($landing_hero['animation'])): ?>
  document.body.classList.add('hero-anim-active');
  <?php endif; ?>
</script>

<?php mc_render_loader_boot(['status' => 'Loading medConnect…']); ?>

<?php if (!empty($landing_maintenance['enabled'])): ?>
<div class="landing-maintenance-banner" role="status">
  <?= htmlspecialchars($landing_maintenance['message']) ?>
</div>
<?php endif; ?>



<!-- CANVAS BUBBLES ONLY (background image is on .hero via CSS) -->

<div class="bg-canvas" aria-hidden="true">

  <canvas id="bubble-canvas"></canvas>

</div>



<!-- NAVBAR -->
<?php
$navVariant = 'landing';
require __DIR__ . '/partials/landing_navbar.php';
?>



<!-- HERO -->

<section class="hero hero--cinematic" id="hero-section">

  <div class="hero-media" aria-hidden="true" style="background-image:url('<?= htmlspecialchars($landing_hero['bg_image']) ?>')"></div>

  <div class="hero-overlay" aria-hidden="true"></div>

  <div class="hero-inner">

    <div class="hero-stage">

      <div class="hero-copy-shift" id="hero-copy">

        <div class="hero-left d-flex flex-column">

          <button type="button" class="hero-badge" id="open-location-modal" aria-haspopup="dialog" aria-controls="location-modal">

            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>

            City Health Office · Bago City

          </button>

          <h1 class="hero-title w-100 d-flex flex-column">

            <span class="hero-title__line"><span class="title-accent"><?= htmlspecialchars($landing_hero['accent']) ?></span> <?= htmlspecialchars($landing_hero['line1']) ?></span>

            <span class="hero-title__line"><?= htmlspecialchars($landing_hero['line2']) ?></span>

          </h1>

          <p class="hero-desc w-100">

            <?= htmlspecialchars($landing_hero['subheading']) ?>

          </p>

          <ul class="hero-trust-chips" aria-label="Platform highlights">

            <li class="hero-trust-chip">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a4 4 0 0 1 4 4c0 1.95-1.4 3.58-3.25 3.93L12 22l-.75-12.07A4.001 4.001 0 0 1 12 2z"/><path d="M9 6h6"/></svg>
              <span>AI Triage</span>
            </li>

            <li class="hero-trust-chip">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>
              <span>Video Consult</span>
            </li>

            <li class="hero-trust-chip">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span>Secure Records</span>
            </li>

            <li class="hero-trust-chip">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
              <span>Bago City CHO</span>
            </li>

          </ul>

          <div class="hero-ctas d-flex flex-sm-row flex-column align-items-stretch align-items-sm-center gap-3">

            <button type="button" class="cta-primary hero-cta" id="open-book-cta">

              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>

              <span>Book Consultation</span>

              <svg class="hero-cta__chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>

            </button>

            <a href="#how-it-works" class="cta-secondary hero-cta hero-cta--outline">

              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8" fill="currentColor" stroke="none"/></svg>

              <span>How It Works</span>

              <svg class="hero-cta__chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>

            </a>

          </div>

        </div>

      </div>

      <!-- SIGN-IN PANEL (inline hero — does not navigate away) -->
      <div
        id="signin-modal"
        class="hero-signin-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="signin-panel-title"
        aria-hidden="true"
        hidden
      >

        <div class="signin-modal-wrap">

          <button type="button" class="signin-modal-close" id="close-signin-modal" aria-label="Close sign in">&times;</button>

          <div class="signin-modal-inner">

            <div class="signin-card signin-card--hero-inline" id="signin-card">

            <div class="card-top signin-card__head">

              <img src="<?= $asset ?>/assets/img/medcon_logo.png" alt="medConnect" class="signin-card__logo" />

              <div class="card-top__text">

                <h2 class="card-title" id="signin-panel-title">Sign In</h2>

                <p class="card-sub">Secure access to your MedConnect account.</p>

              </div>

            </div>

            <div class="signin-card__primary">

            <div class="alert" id="alert" role="alert" aria-live="polite"></div>

            <?php if (!empty($_GET['registered'])): ?>
            <div class="alert" style="display:block;background:#ecfdf5;border:1px solid #6ee7b7;color:#047857;margin-bottom:14px;" role="alert">
              Patient account has been successfully created. Please sign in using your registered email and complete your account setup.
            </div>
            <?php endif; ?>

            <?php if (!empty($_GET['setup_complete'])): ?>
            <div class="alert" style="display:block;background:#ecfdf5;border:1px solid #6ee7b7;color:#047857;margin-bottom:14px;" role="alert">
              Your password has been set. Sign in with your email and new password to access your patient portal.
            </div>
            <?php endif; ?>

            <?php if (!empty($_GET['session_expired'])): ?>
            <div class="alert" style="display:block;background:#fef3c7;border:1px solid #fcd34d;color:#92400e;margin-bottom:14px;" role="alert">
              Your session expired due to inactivity.
            </div>
            <?php endif; ?>

            <?php if (!empty($_GET['signin'])): ?>
            <div class="alert" style="display:block;background:#eff6ff;border:1px solid #93c5fd;color:#1e40af;margin-bottom:14px;" role="alert">
              Please sign in to access your portal.
            </div>
            <?php endif; ?>

            <form id="login-form" novalidate>

              <div class="form-group">

                <label for="email">Email Address</label>

                <div class="input-wrap">

                  <span class="input-icon" aria-hidden="true">

                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>

                  </span>

                  <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" required />

                </div>

                <span class="field-error" id="email-error" role="alert"></span>

              </div>

              <div class="form-group">

                <label for="password">Password</label>

                <div class="input-wrap">

                  <span class="input-icon" aria-hidden="true">

                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>

                  </span>

                  <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required />

                  <button type="button" class="toggle-pwd" id="toggle-pwd" aria-label="Show password">

                    <svg id="eye-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">

                      <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>

                      <circle cx="12" cy="12" r="3"/>

                    </svg>

                  </button>

                </div>

                <span class="field-error" id="password-error" role="alert"></span>

              </div>

              <div class="form-footer-row">
                <label class="remember-me-label" for="remember-me">
                  <input type="checkbox" id="remember-me" name="remember_me" value="1" />
                  <span>Remember me</span>
                </label>
                <a href="#" class="forgot-link" id="forgot-link">Forgot password?</a>
              </div>

              <div id="mc-recaptcha-v2" hidden style="margin:12px 0 2px">
                <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) ?>"></div>
              </div>

              <button type="submit" class="btn-signin" id="submit-btn">

                <span id="btn-text">Sign In</span>

                <span id="btn-spinner" class="spinner" hidden aria-hidden="true"></span>

              </button>

            </form>

            <p class="card-register">

              New patient? <a href="<?= $asset ?>/app/controllers/auth/register.controller.php">Create a patient account</a>

            </p>

            </div><!-- /.signin-card__primary -->

            <div class="signin-card__extras">

            <p class="provider-note provider-note--compact">

              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>

              <span class="provider-note__text">Provider accounts are managed by the system administrator.</span>

            </p>

            <div class="emergency-note emergency-note--compact" role="note">

              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>

              <span><strong>Emergencies:</strong> Go to the nearest facility or call local emergency services.</span>

            </div>

            <div class="card-badges card-badges--compact">

              <span class="trust-badge">

                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>

                Data Privacy Compliant

              </span>

              <span class="trust-badge">

                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>

                Secure Access

              </span>

              <span class="trust-badge">

                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>

                Verified Providers

              </span>

            </div>

            </div><!-- /.signin-card__extras -->

          </div><!-- /.signin-card -->

          </div><!-- /.signin-modal-inner -->

        </div><!-- /.signin-modal-wrap -->

      </div><!-- /#signin-modal -->

    </div><!-- /.hero-stage -->

  </div>

  <?php if (!empty($landing_sections['announcements'])): ?>
  <?php require __DIR__ . '/partials/announcements_section.php'; ?>
  <?php endif; ?>

  <div id="hero-theme-sentinel" class="hero-theme-sentinel" aria-hidden="true"></div>

</section>



<!-- SERVICES SECTION -->

<?php if (!empty($landing_sections['services'])): ?>
<section id="services-section" class="services-section">

  <div class="landing-ambient" aria-hidden="true">
    <span class="landing-ambient__orb landing-ambient__orb--1"></span>
    <span class="landing-ambient__orb landing-ambient__orb--2"></span>
    <span class="landing-ambient__cross landing-ambient__cross--1"></span>
    <span class="landing-ambient__pulse landing-ambient__pulse--1"></span>
  </div>

  <div class="services-container">



    <div class="services-header">

      <h2 class="services-title">Our Services</h2>

      <p class="services-desc">

        <span class="services-brand">medConnect</span> is an Online Video Call Consultation and AI-Powered Triage System designed for the <strong>City Health Office</strong> of Bago City.<br/>

        The system improves healthcare accessibility, supports patient prioritization, centralizes medical records, and strengthens follow-up care for non-emergency cases.

      </p>

    </div>



    <div class="services-grid">



      <div class="service-card">

        <div class="service-icon">

          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>

        </div>

        <h3 class="service-card-title">AI-Assisted Triage</h3>

        <p class="service-card-desc">Smart symptom assessment helps classify patients based on urgency.</p>

      </div>



      <div class="service-card">

        <div class="service-icon">

          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 10l4.553-2.069A1 1 0 0 1 21 8.82v6.36a1 1 0 0 1-1.447.89L15 14"/><rect width="15" height="14" x="1" y="5" rx="2" ry="2"/></svg>

        </div>

        <h3 class="service-card-title">Medical Video Consultation</h3>

        <p class="service-card-desc">Secure video calls for remote consultations, reducing unnecessary travel.</p>

      </div>



      <div class="service-card">

        <div class="service-icon">

          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/></svg>

        </div>

        <h3 class="service-card-title">Centralized Medical Records</h3>

        <p class="service-card-desc">All patient information stored in one secure digital platform.</p>

      </div>



      <div class="service-card">

        <div class="service-icon">

          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>

        </div>

        <h3 class="service-card-title">Post-Consultation Monitoring</h3>

        <p class="service-card-desc">Helps track patient progress and schedule follow-ups.</p>

      </div>



    </div>

  </div>

</section>
<?php endif; ?>



<!-- HOW IT WORKS -->
<?php if (!empty($landing_sections['how_it_works'])): ?>
<section id="how-it-works" class="services-section">

  <div class="services-container">

    <div class="services-header">

      <h2 class="services-title">How It Works</h2>

      <p class="services-desc">

        A simple four-step process for non-emergency healthcare access through <span class="services-brand">medConnect</span>.

      </p>

    </div>

    <div class="services-grid">

      <div class="service-card">

        <div class="service-icon">

          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>

        </div>

        <h3 class="service-card-title">Register &amp; Verify</h3>

        <p class="service-card-desc">Create your patient account and verify your identity securely.</p>

      </div>

      <div class="service-card">

        <div class="service-icon">

          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>

        </div>

        <h3 class="service-card-title">AI-Assisted Triage</h3>

        <p class="service-card-desc">Complete symptom assessment to help prioritize your care needs.</p>

      </div>

      <div class="service-card">

        <div class="service-icon">

          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 10l4.553-2.069A1 1 0 0 1 21 8.82v6.36a1 1 0 0 1-1.447.89L15 14"/><rect width="15" height="14" x="1" y="5" rx="2" ry="2"/></svg>

        </div>

        <h3 class="service-card-title">Video Consultation</h3>

        <p class="service-card-desc">Connect with a licensed provider through secure video consultation.</p>

      </div>

      <div class="service-card">

        <div class="service-icon">

          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>

        </div>

        <h3 class="service-card-title">Records &amp; Follow-Up</h3>

        <p class="service-card-desc">Consultation notes are saved and follow-up care can be scheduled.</p>

      </div>

    </div>

  </div>

</section>
<?php endif; ?>



<!-- ABOUT US / OUR TEAM -->
<?php require __DIR__ . '/partials/about_team_section.php'; ?>



<!-- CONTACT / FOOTER SECTION -->

<?php if (!empty($landing_sections['contact'])): ?>
<section id="contact-section" class="contact-section">

  <div class="landing-ambient landing-ambient--contact" aria-hidden="true">
    <span class="landing-ambient__orb landing-ambient__orb--3"></span>
    <span class="landing-ambient__cross landing-ambient__cross--2"></span>
    <span class="landing-ambient__pulse landing-ambient__pulse--2"></span>
  </div>

  <!-- top separator -->

  <div class="contact-separator" aria-hidden="true"></div>



  <div class="contact-container">

    <div class="contact-grid">



      <!-- Col 1 — Brand -->

      <div class="contact-col contact-col--brand">

        <div class="contact-brand">

          <img src="<?= $asset ?>/assets/img/medcon_logo.png" alt="medConnect" class="contact-logo" loading="lazy" decoding="async" width="38" height="38"/>

          <span class="contact-brand-name">med<span class="contact-brand-accent">Connect</span></span>

        </div>

        <p class="contact-brand-desc">

          An Online Video Call Consultation and AI-Powered Triage System serving the City Health Office of Bago City. Bridging patients and healthcare providers for non-emergency care.

        </p>

        <div class="contact-socials">

          <a href="#" class="contact-social-link" aria-label="Facebook">

            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>

          </a>

          <a href="#" class="contact-social-link" aria-label="Twitter / X">

            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 4l16 16M4 20L20 4"/><path d="M4 4l16 16M4 20L20 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>

          </a>

          <a href="#" class="contact-social-link" aria-label="Email">

            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>

          </a>

        </div>

      </div>



      <!-- Col 2 — Services -->

      <div class="contact-col">

        <h4 class="contact-col-title">Services</h4>

        <ul class="contact-links">

          <li><a href="#services-section">AI-Assisted Triage</a></li>

          <li><a href="#services-section">Medical Video Consultation</a></li>

          <li><a href="#services-section">Centralized Medical Records</a></li>

          <li><a href="#services-section">Post-Consultation Monitoring</a></li>

        </ul>

      </div>



      <!-- Col 3 — Quick Info -->

      <div class="contact-col">

        <h4 class="contact-col-title">Quick Info</h4>

        <ul class="contact-links">

          <li><a href="#about-section">About medConnect</a></li>

          <li><a href="#">Patient Registration</a></li>

          <li><a href="#">Privacy Policy</a></li>

          <li><a href="#">Terms of Use</a></li>

        </ul>

        <div class="contact-emergency">

          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>

          For emergencies, go to the nearest healthcare facility immediately.

        </div>

      </div>



      <!-- Col 4 — Contact Info -->

      <div class="contact-col">

        <h4 class="contact-col-title">Contact Info</h4>

        <ul class="contact-details">

          <li>

            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>

            City Health Office, Bago City, Negros Occidental

          </li>

          <li>

            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.18 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>

            (034) 445-8000

          </li>

          <li>

            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>

            cho.bagocity@example.gov.ph

          </li>

          <li>

            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>

            Mon – Fri, 8:00 AM – 5:00 PM

          </li>

        </ul>

      </div>



    </div>



    <!-- Bottom bar -->

    <div class="contact-bottom">

      <p class="contact-copy">&copy; <?php echo date('Y'); ?> medConnect &mdash; City Health Office of Bago City. All rights reserved.</p>

      <p class="contact-copy-note">Non-emergency use only. For life-threatening situations, call emergency services immediately.</p>

    </div>



  </div>

</section>
<?php endif; ?>



<?php if (!empty((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) && !empty((string) (defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : ''))): ?>
  <?php if (strtolower((string) (defined('RECAPTCHA_VERSION') ? RECAPTCHA_VERSION : 'v3')) === 'v2'): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php else: ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars((string) (defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '')) ?>"></script>
  <?php endif; ?>
<?php endif; ?>

<script src="<?= $asset ?>/assets/js/landing-about-project-milestone.js?v=2" defer></script>
  <script src="<?= $asset ?>/assets/js/landing-scroll-animations.js?v=9" defer></script>
<script src="<?= $asset ?>/assets/js/landing-interactions.js?v=7" defer></script>
<script src="<?= $asset ?>/assets/js/landing-hero-search.js?v=4" defer></script>
<script src="<?= $asset ?>/assets/js/landing-fab.js" defer></script>
<script src="<?= $asset ?>/assets/js/draggable-fab.js?v=1" defer></script>
<script src="<?= $asset ?>/assets/js/signin-req-drawer.js?v=4" defer></script>

<script src="<?= $asset ?>/assets/js/script.js?v=20260711b"></script>
<?php require_once VIEWS_PATH . '/partials/theme_scripts.php'; ?>

<script>

  // Base path works on both localhost subfolder and domain root

  window.APP_BASE = <?= json_encode($asset) ?>;

</script>



<!-- Forgot Password Modal — 3-Step OTP Flow -->

<div id="forgot-modal" style="display:none;position:fixed;inset:0;z-index:1100;background:rgba(4,12,24,0.82);backdrop-filter:blur(7px);align-items:center;justify-content:center;padding:16px">

  <div style="background:linear-gradient(160deg,#0b1f38,#071525);border:1px solid rgba(45,212,191,0.20);border-radius:18px;padding:32px 30px;width:100%;max-width:420px;box-shadow:0 24px 72px rgba(0,0,0,0.60);position:relative">

    <button id="forgot-close" style="position:absolute;top:14px;right:16px;background:none;border:none;cursor:pointer;color:rgba(255,255,255,0.45);font-size:22px;line-height:1">&times;</button>



    <!-- Step dots -->

    <div style="display:flex;align-items:center;margin-bottom:24px">

      <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1">

        <div id="fd1" style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">1</div>

        <span id="fl1" style="font-size:10px;font-weight:600;color:#3b82f6">Email</span>

      </div>

      <div id="fln1" style="flex:1;height:2px;background:rgba(255,255,255,0.10);margin-bottom:14px"></div>

      <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1">

        <div id="fd2" style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,0.07);color:rgba(255,255,255,0.35);border:2px solid rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">2</div>

        <span id="fl2" style="font-size:10px;font-weight:600;color:rgba(255,255,255,0.35)">OTP</span>

      </div>

      <div id="fln2" style="flex:1;height:2px;background:rgba(255,255,255,0.10);margin-bottom:14px"></div>

      <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1">

        <div id="fd3" style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,0.07);color:rgba(255,255,255,0.35);border:2px solid rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">3</div>

        <span id="fl3" style="font-size:10px;font-weight:600;color:rgba(255,255,255,0.35)">New Password</span>

      </div>

    </div>



    <div id="fp-alert" style="display:none;padding:11px 14px;border-radius:9px;font-size:13px;margin-bottom:16px"></div>



    <!-- Step 1 -->

    <div id="fp-s1">

      <div style="font-size:16px;font-weight:800;color:#fff;margin-bottom:4px">Forgot Password?</div>

      <div style="font-size:13px;color:rgba(255,255,255,0.55);margin-bottom:18px">Enter your email to receive a 6-digit OTP.</div>

      <label style="display:block;font-size:12.5px;font-weight:600;color:rgba(255,255,255,0.80);margin-bottom:6px">Email Address</label>

      <input type="email" id="fp-email" placeholder="your.email@example.com"

        style="width:100%;height:46px;padding:0 14px;border:1.5px solid rgba(45,212,191,0.25);border-radius:10px;font-size:14px;font-family:inherit;color:#fff;background:rgba(255,255,255,0.07);outline:none;box-sizing:border-box;margin-bottom:14px"/>

      <button id="fp-send" style="width:100%;height:48px;border:none;border-radius:11px;cursor:pointer;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;font-size:14.5px;font-weight:700;font-family:inherit">

        <span id="fp-send-t">Send OTP</span>

        <span id="fp-send-s" hidden style="display:inline-block;width:14px;height:14px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle"></span>

      </button>

    </div>



    <!-- Step 2 -->

    <div id="fp-s2" hidden>

      <div style="font-size:16px;font-weight:800;color:#fff;margin-bottom:4px">Enter OTP</div>

      <div id="fp-otp-note" style="font-size:13px;color:rgba(255,255,255,0.55);margin-bottom:18px">OTP sent to your email.</div>

      <label style="display:block;font-size:12.5px;font-weight:600;color:rgba(255,255,255,0.80);margin-bottom:6px">6-Digit OTP</label>

      <input type="text" id="fp-otp" maxlength="6" inputmode="numeric" placeholder="000000"

        style="width:100%;height:54px;padding:0 14px;border:1.5px solid rgba(45,212,191,0.25);border-radius:10px;font-size:26px;font-weight:700;letter-spacing:8px;text-align:center;font-family:inherit;color:#fff;background:rgba(255,255,255,0.07);outline:none;box-sizing:border-box;margin-bottom:14px"/>

      <button id="fp-verify" style="width:100%;height:48px;border:none;border-radius:11px;cursor:pointer;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;font-size:14.5px;font-weight:700;font-family:inherit">

        <span id="fp-verify-t">Verify OTP</span>

        <span id="fp-verify-s" hidden style="display:inline-block;width:14px;height:14px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle"></span>

      </button>

      <p style="text-align:center;margin-top:12px;font-size:13px;color:rgba(255,255,255,0.50)">

        Didn't receive it? <button id="fp-resend" style="background:none;border:none;color:#2dd4bf;cursor:pointer;font-size:13px;text-decoration:underline;padding:0;font-family:inherit">Resend</button>

        <span id="fp-cd" style="color:rgba(255,255,255,0.35);font-size:13px"></span>

      </p>

    </div>



    <!-- Step 3 -->

    <div id="fp-s3" hidden>

      <div style="font-size:16px;font-weight:800;color:#fff;margin-bottom:4px">New Password</div>

      <div style="font-size:13px;color:rgba(255,255,255,0.55);margin-bottom:18px">OTP verified. Set your new password.</div>

      <label style="display:block;font-size:12.5px;font-weight:600;color:rgba(255,255,255,0.80);margin-bottom:6px">New Password</label>

      <input type="password" id="fp-pw" placeholder="At least 6 characters"

        style="width:100%;height:46px;padding:0 14px;border:1.5px solid rgba(45,212,191,0.25);border-radius:10px;font-size:14px;font-family:inherit;color:#fff;background:rgba(255,255,255,0.07);outline:none;box-sizing:border-box;margin-bottom:12px"/>

      <label style="display:block;font-size:12.5px;font-weight:600;color:rgba(255,255,255,0.80);margin-bottom:6px">Confirm Password</label>

      <input type="password" id="fp-cpw" placeholder="Repeat your password"

        style="width:100%;height:46px;padding:0 14px;border:1.5px solid rgba(45,212,191,0.25);border-radius:10px;font-size:14px;font-family:inherit;color:#fff;background:rgba(255,255,255,0.07);outline:none;box-sizing:border-box;margin-bottom:14px"/>

      <button id="fp-reset" style="width:100%;height:48px;border:none;border-radius:11px;cursor:pointer;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;font-size:14.5px;font-weight:700;font-family:inherit">

        <span id="fp-reset-t">Reset Password</span>

        <span id="fp-reset-s" hidden style="display:inline-block;width:14px;height:14px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle"></span>

      </button>

    </div>



    <!-- Success -->

    <div id="fp-done" hidden style="text-align:center;padding:10px 0">

      <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#16a34a,#22c55e);display:flex;align-items:center;justify-content:center;margin:0 auto 16px">

        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>

      </div>

      <div style="font-size:17px;font-weight:800;color:#fff;margin-bottom:8px">Password Reset!</div>

      <div style="font-size:13px;color:rgba(255,255,255,0.55);margin-bottom:20px">Your password has been updated. You can now sign in.</div>

      <button id="fp-signin" style="width:100%;height:48px;border:none;border-radius:11px;cursor:pointer;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;font-size:14.5px;font-weight:700;font-family:inherit">Sign In Now</button>

    </div>

  </div>

</div>



<script src="<?= $asset ?>/assets/js/forgot-password.js"></script>



<script>

/* ===== HERO LOGO CAROUSEL ===== */

(function () {

  const track = document.getElementById('hrcTrack');

  const dots  = document.querySelectorAll('.hrc-dot');

  if (!track || !dots.length) return;



  const total = dots.length;

  let cur = 0, timer;



  function goTo(i) {

    cur = (i + total) % total;

    track.style.transform = 'translateX(-' + (cur * 100) + '%)';

    dots.forEach((d, idx) => d.classList.toggle('active', idx === cur));

  }



  function start() {

    clearInterval(timer);

    timer = setInterval(() => goTo(cur + 1), 3500);

  }



  dots.forEach(d => d.addEventListener('click', () => {

    goTo(+d.dataset.i);

    start();

  }));



  const card = document.querySelector('.hrc-card');

  if (card) {

    card.addEventListener('mouseenter', () => clearInterval(timer));

    card.addEventListener('mouseleave', start);

  }



  goTo(0);

  start();

})();

</script>



<!-- ANNOUNCEMENT MODAL -->

<div id="announcement-modal" role="dialog" aria-modal="true" aria-label="Announcement">

  <div class="ann-box">

    <div class="ann-accent" aria-hidden="true"></div>

    <div class="ann-header">

      <div class="ann-header-left">

        <div class="ann-header-icon" aria-hidden="true">

          <svg id="ann-bell-svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">

            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>

            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>

          </svg>

        </div>

        <div class="ann-header-text">

          <p class="ann-eyebrow">medConnect</p>

          <h2 class="ann-title">Announcement</h2>

        </div>

      </div>

      <button type="button" class="ann-close-btn" id="close-announcement-modal" aria-label="Close">&times;</button>

    </div>



    <div class="ann-body" id="ann-modal-body">

      <div class="ann-body-icon-wrap" id="ann-modal-icon" aria-hidden="true">

        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">

          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>

        </svg>

      </div>

      <div id="ann-modal-content">
        <p class="ann-body-msg">Loading announcements…</p>
      </div>

    </div>



    <div class="ann-footer">

      <button type="button" class="ann-btn-close" id="btn-close-announcement">Close</button>

    </div>



  </div>

</div>



<!-- LOCATION MODAL -->

<div id="location-modal" class="location-modal" role="dialog" aria-modal="true" aria-labelledby="location-modal-title" hidden>

  <div class="location-modal__box">

    <div class="location-modal__accent" aria-hidden="true"></div>

    <header class="location-modal__header">

      <div class="location-modal__header-left">

        <div class="location-modal__header-icon" aria-hidden="true">

          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>

        </div>

        <div class="location-modal__header-text">

          <p class="location-modal__eyebrow">City Health Office · Bago City</p>

          <h2 class="location-modal__title" id="location-modal-title">Location</h2>

        </div>

      </div>

      <button type="button" class="location-modal__close" id="close-location-modal" aria-label="Close location">&times;</button>

    </header>

    <div class="location-modal__body">

      <div class="location-modal__info">

        <div class="location-modal__info-block">

          <h3 class="location-modal__info-label">Located in:</h3>

          <p class="location-modal__info-text">The City Government of Bago</p>

        </div>

        <div class="location-modal__info-block">

          <h3 class="location-modal__info-label">Address:</h3>

          <p class="location-modal__info-text">Bago City Hall, 6101, Bago City, Negros Occidental</p>

        </div>

        <div class="location-modal__info-block">

          <h3 class="location-modal__info-label">Phone:</h3>

          <p class="location-modal__info-text">

            <a href="tel:+63344610118" class="location-modal__info-link">(034) 461 0118</a>

          </p>

        </div>

      </div>

      <div class="location-modal__map-wrap">

        <iframe

          id="location-modal-map"

          class="location-modal__map"

          title="Bago City Health Office map"

          data-src="https://maps.google.com/maps?q=Bago+City+Health+Office,+Bago+City,+Negros+Occidental&amp;t=&amp;z=15&amp;ie=UTF8&amp;iwloc=&amp;output=embed"

          loading="lazy"

          referrerpolicy="no-referrer-when-downgrade"

          allowfullscreen

        ></iframe>

      </div>

    </div>

  </div>

</div>



<?php require __DIR__ . '/partials/landing_fab.php'; ?>
<?php require __DIR__ . '/partials/landing_theme_fab.php'; ?>
<?php $landingThemeScrollVer = (int) @filemtime(ASSETS_PATH . '/js/landing-theme-scroll.js'); ?>
<script src="<?= $asset ?>/assets/js/landing-theme-scroll.js?v=<?= $landingThemeScrollVer ?>"></script>
<?php require __DIR__ . '/partials/landing_fab_modals.php'; ?>
<?php require __DIR__ . '/partials/signin_req_drawer.php'; ?>
<?php require __DIR__ . '/partials/faq_chatbot.php'; ?>



<script src="<?= $asset ?>/assets/js/landing-announcements.js?v=5"></script>
<script src="<?= $asset ?>/assets/js/landing-location.js?v=1"></script>

</body>

</html>


