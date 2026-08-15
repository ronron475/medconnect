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
require_once __DIR__ . '/_portal_access.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';

$page_title = 'AI Review Assignments';
$apiUrl = ASSET_BASE . '/app/api/admin/triage_review_assignments.php';
$is_superadmin_portal = defined('MC_PORTAL_SHELL') && MC_PORTAL_SHELL === 'superadmin';
$portal_eyebrow = $is_superadmin_portal ? 'Super Administration · AI Triage' : 'Administration · AI Triage';
$cssVer = (int) @filemtime(ASSETS_PATH . '/css/admin-ai-review.css');
$jsVer = (int) @filemtime(ASSETS_PATH . '/js/admin-ai-review.js');

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.1">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-ai-review.css?v=<?= $cssVer ?>">

<article class="air-review-page staff-apps-page" id="airReviewRoot" data-api="<?= htmlspecialchars($apiUrl) ?>">

<header class="staff-apps-hero">
  <div class="staff-apps-hero__content">
    <span class="staff-apps-hero__eyebrow"><?= htmlspecialchars($portal_eyebrow) ?></span>
    <h1 class="staff-apps-hero__title">AI Review Assignments</h1>
    <p class="staff-apps-hero__desc">
      Reassign reviewing doctors for non-urgent self-care cases. Patients stay locked to the assigned provider when booking follow-up consultations.
    </p>
  </div>
</header>

<div class="staff-apps-stats" id="airReviewStats" aria-live="polite">
  <div class="staff-apps-stat">
    <div class="staff-apps-stat__value" id="airStatTotal">—</div>
    <div class="staff-apps-stat__label">Total cases (30 days)</div>
  </div>
  <div class="staff-apps-stat staff-apps-stat--pending">
    <div class="staff-apps-stat__value" id="airStatPending">—</div>
    <div class="staff-apps-stat__label">Pending review</div>
  </div>
  <div class="staff-apps-stat staff-apps-stat--active">
    <div class="staff-apps-stat__value" id="airStatApproved">—</div>
    <div class="staff-apps-stat__label">Approved</div>
  </div>
  <div class="staff-apps-stat air-review-stat--unassigned">
    <div class="staff-apps-stat__value" id="airStatUnassigned">—</div>
    <div class="staff-apps-stat__label">Unassigned</div>
  </div>
</div>

<div class="staff-apps-note" role="note">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <span>Choose a reviewing doctor in <strong>Actions</strong>, then click <strong>Save</strong>. The patient stays linked to that provider for follow-up booking.</span>
</div>

<div class="staff-apps-card">
  <div class="staff-apps-card__toolbar">
    <div class="staff-apps-search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" id="aiReviewSearch" class="staff-apps-search__input" placeholder="Search patient or concern…" aria-label="Search AI review cases">
    </div>
    <select id="aiReviewFilter" class="staff-apps-filter" aria-label="Filter cases">
      <option value="active">Pending + approved (30 days)</option>
      <option value="pending">Pending review only</option>
      <option value="approved">Approved only</option>
    </select>
    <button type="button" class="mc-btn mc-btn--outline" id="aiReviewRefresh">Refresh</button>
    <span class="staff-apps-card__count" id="aiReviewCount"></span>
    <span class="air-review-updated" id="aiReviewStatus"></span>
  </div>

  <div class="staff-apps-table-wrap">
    <table class="staff-apps-table air-review-table" id="aiReviewTable">
      <thead>
        <tr>
          <th>Patient</th>
          <th>Concern</th>
          <th>Status</th>
          <th>Assigned reviewer</th>
          <th>Submitted</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="aiReviewTbody">
        <tr><td colspan="6"><div class="staff-apps-loading"><div class="staff-apps-loading__spinner" aria-hidden="true"></div>Loading AI review cases…</div></td></tr>
      </tbody>
    </table>
  </div>
</div>

</article>

<script src="<?= ASSET_BASE ?>/assets/js/admin-ai-review.js?v=<?= $jsVer ?>"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
