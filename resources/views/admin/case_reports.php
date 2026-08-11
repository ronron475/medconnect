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
require_once BASE_PATH . '/app/includes/auth_guard.php';

if (!defined('MC_PORTAL_SHELL') || MC_PORTAL_SHELL !== 'superadmin') {
    require_once __DIR__ . '/_portal_access.php';
}

require_once BASE_PATH . '/app/includes/case_reports.php';
require_once BASE_PATH . '/app/includes/portal_auth.php';

$page_title = 'Case Reports';
$apiUrl = ASSET_BASE . '/app/api/admin/case_reports.php';
$isSuperadmin = portal_is_superadmin();
$isSuperadminPortal = defined('MC_PORTAL_SHELL') && MC_PORTAL_SHELL === 'superadmin';
$portalEyebrow = $isSuperadminPortal ? 'Super Administration · Operations' : 'Administration · Operations';
$cssVer = (int) @filemtime(ASSETS_PATH . '/css/admin-case-reports.css');
$jsVer = (int) @filemtime(ASSETS_PATH . '/js/admin-case-reports.js');
$deepLinkId = (int) ($_GET['id'] ?? 0);

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.1">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-case-reports.css?v=<?= $cssVer ?>">

<article class="cr-page staff-apps-page" id="caseReportsRoot"
         data-api="<?= htmlspecialchars($apiUrl) ?>"
         data-superadmin="<?= $isSuperadmin ? '1' : '0' ?>"
         data-deep-link="<?= (int) $deepLinkId ?>">

<header class="staff-apps-hero">
  <div class="staff-apps-hero__content">
    <span class="staff-apps-hero__eyebrow"><?= htmlspecialchars($portalEyebrow) ?></span>
    <h1 class="staff-apps-hero__title">Violation Reports</h1>
    <p class="staff-apps-hero__desc">
      Review provider-reported concerns from Chief Complaint cases and live video consultations. Dismiss false positives, confirm possible violations when appropriate, and apply proportional account restrictions.
    </p>
  </div>
</header>

<div class="staff-apps-toolbar">
  <label class="staff-apps-toolbar__field">
    <span class="staff-apps-toolbar__label">Filter</span>
    <select id="crStatusFilter" class="mc-input">
      <option value="all">All reports</option>
      <option value="pending">Pending</option>
      <option value="under_review">Under review</option>
      <option value="escalated">Escalated</option>
      <option value="confirmed">Confirmed</option>
      <option value="dismissed">Dismissed</option>
    </select>
  </label>
</div>

<div class="staff-apps-table-wrap">
  <table class="staff-apps-table" id="crReportsTable">
    <thead>
      <tr>
        <th>Patient</th>
        <th>Provider</th>
        <th>Source</th>
        <th>Consultation</th>
        <th>Reason</th>
        <th>Date</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="crReportsBody">
      <tr><td colspan="8" class="staff-apps-empty">Loading reports…</td></tr>
    </tbody>
  </table>
</div>
</article>

<div id="crDetailModal" class="cr-modal" aria-hidden="true">
  <div class="cr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="crDetailTitle">
    <header class="cr-modal__header">
      <h2 id="crDetailTitle" class="cr-modal__title">Case report review</h2>
      <button type="button" class="cr-modal__close" data-cr-close aria-label="Close">&times;</button>
    </header>
    <div class="cr-modal__body" id="crDetailBody"></div>
    <footer class="cr-modal__footer" id="crDetailFooter"></footer>
  </div>
</div>

<script src="<?= ASSET_BASE ?>/assets/js/admin-case-reports.js?v=<?= $jsVer ?>"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
