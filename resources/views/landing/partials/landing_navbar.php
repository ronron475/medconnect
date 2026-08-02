<?php
/**
 * Shared landing navbar — premium healthcare layout
 *
 * @var string $asset       Asset base URL
 * @var string $navVariant  'landing' | 'ann-list'
 * @var string $backIcon    SVG markup for Back to Home (ann-list only)
 */
$navVariant = $navVariant ?? 'landing';
$homeUrl = $asset . '/index.php';

$navLinkItems = [
    ['href' => '#hero-section', 'id' => 'nav-home', 'label' => 'Home', 'dataNav' => null, 'active' => true],
    ['href' => '#announcements-section', 'id' => null, 'label' => 'Announcements', 'dataNav' => 'announcements-section', 'active' => false],
    ['href' => '#services-section', 'id' => null, 'label' => 'Services', 'dataNav' => 'services-section', 'active' => false],
    ['href' => '#how-it-works', 'id' => null, 'label' => 'How It Works', 'dataNav' => 'how-it-works', 'active' => false],
    ['href' => '#about-section', 'id' => null, 'label' => 'About', 'dataNav' => 'about-section', 'active' => false],
    ['href' => '#contact-section', 'id' => null, 'label' => 'Contact', 'dataNav' => 'contact-section', 'active' => false],
];

$renderNavLinks = static function (string $class = '') use ($navLinkItems): void {
    echo '<ul class="nav-links' . ($class !== '' ? ' ' . htmlspecialchars($class) : '') . '">';
    foreach ($navLinkItems as $item) {
        $attrs = 'href="' . htmlspecialchars($item['href']) . '"';
        if ($item['id']) {
            $attrs .= ' id="' . htmlspecialchars($item['id']) . '"';
        }
        if ($item['dataNav']) {
            $attrs .= ' data-nav="' . htmlspecialchars($item['dataNav']) . '"';
        }
        $active = $item['active'] ? ' is-active' : '';
        echo '<li><a ' . $attrs . ' class="nav-link' . $active . '">' . htmlspecialchars($item['label']) . '</a></li>';
    }
    echo '</ul>';
};
?>
<nav class="navbar landing-navbar landing-navbar--premium" id="navbar" aria-label="Main navigation">
  <div class="nav-container nav-container--premium">

    <div class="nav-bar__left">
      <a href="<?= htmlspecialchars($homeUrl) ?>" class="nav-logo">
        <img src="<?= htmlspecialchars($asset) ?>/assets/img/medcon_logo.png" alt="" class="nav-logo__icon" width="42" height="42" />
        <span class="logo-text"><span class="logo-brand-med">med</span><span class="logo-accent">Connect</span></span>
        <img src="<?= htmlspecialchars($asset) ?>/assets/img/bcclogo.png" alt="Bago City College" class="nav-logo__partner nav-logo__partner--bcc" width="108" height="30" decoding="async" />
      </a>
    </div>

    <?php if ($navVariant === 'landing'): ?>

    <div class="nav-bar__center" aria-label="Primary navigation">
      <?php $renderNavLinks('nav-links--desktop'); ?>
    </div>

    <div class="nav-bar__right">
      <?php require __DIR__ . '/landing_search.php'; ?>

      <button type="button" class="btn-nav-signin btn-nav-signin--primary" id="open-signin-modal">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/></svg>
        <span>Sign In</span>
      </button>

      <button class="nav-toggle" id="nav-toggle" type="button" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="nav-menu">
        <span></span><span></span><span></span>
      </button>
    </div>

    <?php else: ?>

    <div class="nav-bar__right">
      <div class="nav-menu nav-menu--ann-list" id="nav-menu">
        <a href="<?= htmlspecialchars($homeUrl) ?>" class="btn-nav-signin ann-list-back-btn">
          <?= $backIcon ?? '' ?>
          <span>Back to Home</span>
        </a>
      </div>
    </div>

    <?php endif; ?>

  </div>
</nav>

<?php if ($navVariant === 'landing'): ?>
<div class="landing-nav-backdrop" id="landing-nav-backdrop" aria-hidden="true" hidden></div>

<div class="nav-menu nav-menu--drawer" id="nav-menu" role="navigation" aria-label="Mobile navigation">
  <?php $renderNavLinks('nav-links--drawer'); ?>
  <button
    type="button"
    class="nav-drawer-theme-btn"
    id="landing-theme-toggle-drawer"
    aria-label="Switch to dark mode"
    aria-pressed="false"
    title="Toggle light / dark mode"
  >
    <span class="nav-drawer-theme-btn__icon" aria-hidden="true">
      <svg class="nav-drawer-theme-btn__icon--moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
      <svg class="nav-drawer-theme-btn__icon--sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="6.34" y2="6.34"/><line x1="17.66" y1="17.66" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="6.34" y2="17.66"/><line x1="17.66" y1="6.34" x2="19.07" y2="4.93"/></svg>
    </span>
    <span class="nav-drawer-theme-btn__label">Dark mode</span>
  </button>
  <button type="button" class="btn-nav-signin btn-nav-signin--primary btn-nav-signin--drawer" id="open-signin-modal-drawer">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/></svg>
    <span>Sign In</span>
  </button>
</div>
<?php endif; ?>
