<?php
/**
 * Shared landing navbar — used on home and All Announcements pages.
 *
 * @var string $asset       Asset base URL
 * @var string $navVariant  'landing' | 'ann-list'
 * @var string $backIcon    SVG markup for Back to Home (ann-list only)
 */
$navVariant = $navVariant ?? 'landing';
$homeUrl = $asset . '/index.php';
?>
<nav class="navbar landing-navbar" id="navbar" aria-label="Main navigation">
  <div class="nav-container">
    <a href="<?= htmlspecialchars($homeUrl) ?>" class="nav-logo">
      <img src="<?= htmlspecialchars($asset) ?>/assets/img/medcon_logo.png" alt="" class="nav-logo__icon" width="40" height="40" />
      <span class="logo-text"><span class="logo-brand-med">med</span><span class="logo-accent">Connect</span></span>
      <span class="nav-logo__divider" aria-hidden="true"></span>
      <img src="<?= htmlspecialchars($asset) ?>/assets/img/bcclogo.png" alt="City Health Office of Bago City" class="nav-logo__partner" />
    </a>

    <?php if ($navVariant === 'landing'): ?>
    <button class="nav-toggle" id="nav-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="nav-menu">
      <span></span><span></span><span></span>
    </button>

    <div class="nav-menu" id="nav-menu">
      <div class="nav-links-wrapper">
        <span class="nav-indicator" aria-hidden="true"></span>
        <ul class="nav-links">
          <li><a href="#hero-section" id="nav-home" class="is-active">Home</a></li>
          <li><a href="#announcements-section" data-nav="announcements-section">Announcements</a></li>
          <li><a href="#services-section" data-nav="services-section">Services</a></li>
          <li><a href="#how-it-works" data-nav="how-it-works">How It Works</a></li>
          <li><a href="#contact-section" data-nav="contact-section">Contact</a></li>
        </ul>
      </div>
      <div class="nav-actions">
        <button type="button" class="btn-nav-signin" id="open-signin-modal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/></svg>
          Sign In
        </button>
        <button type="button" class="btn-nav-book" id="open-book-modal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>
          Announcements
        </button>
      </div>
    </div>
    <?php else: ?>
    <div class="nav-menu nav-menu--ann-list" id="nav-menu">
      <div class="nav-actions">
        <a href="<?= htmlspecialchars($homeUrl) ?>" class="btn-nav-signin ann-list-back-btn">
          <?= $backIcon ?? '' ?>
          <span>Back to Home</span>
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</nav>
