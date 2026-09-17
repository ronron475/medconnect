<?php
/**
 * My Health page toolbar — quick actions only.
 * Page title lives in the topbar (.topbar-title); keep this header free of duplicate titles.
 */
?>
<header class="pmh-hero pmh-hero--toolbar" aria-label="My Health actions">
  <div class="pmh-hero__actions">
    <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php" class="pmh-btn pmh-btn--outline" title="View your health overview">Health Summary</a>
    <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pmh-btn pmh-btn--primary" title="Check symptoms or book a visit">Book a consultation</a>
  </div>
</header>
