<?php
require_once __DIR__ . '/_bootstrap.php';
define('MC_PORTAL_SHELL', 'superadmin');

require_once BASE_PATH . '/app/includes/bhw_application_schema.php';
bhw_application_ensure_schema($pdo);

$page_title = 'Pending BHW Approvals';
$show_approved = isset($_GET['approved']);
$show_rejected = isset($_GET['rejected']);

require_once __DIR__ . '/partials/layout_open.php';
?>

<article class="staff-apps-page staff-apps-page--bhw-approval">

<?php if ($show_approved): ?>
<div class="staff-apps-flash staff-apps-flash--success" role="status">
    <div class="staff-apps-flash__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
    </div>
    <div>
        <p class="staff-apps-flash__title">BHW application approved</p>
        <p class="staff-apps-flash__text">The account is now active and the BHW may log in.</p>
    </div>
</div>
<?php endif; ?>

<?php if ($show_rejected): ?>
<div class="staff-apps-flash staff-apps-flash--warn" role="status">
    <div class="staff-apps-flash__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <div>
        <p class="staff-apps-flash__title">Application rejected</p>
        <p class="staff-apps-flash__text">The submitting administrator has been notified.</p>
    </div>
</div>
<?php endif; ?>

<header class="staff-apps-hero">
    <div class="staff-apps-hero__content">
        <span class="staff-apps-hero__eyebrow">Super Administration · Maker-Checker Review</span>
        <h1 class="staff-apps-hero__title">BHW Approval Queue</h1>
        <p class="staff-apps-hero__desc">Review Barangay Health Worker applications submitted by administrators. Verify identity and assignment documents before activation.</p>
    </div>
    <div class="staff-apps-hero__actions">
        <span class="staff-apps-hero__badge" id="bhwPendingBadge">
            <span class="staff-apps-hero__badge-dot" aria-hidden="true"></span>
            0 pending
        </span>
    </div>
</header>

<div class="staff-apps-stats" id="bhwApprovalStats" aria-live="polite">
    <div class="staff-apps-stat staff-apps-stat--pending">
        <div class="staff-apps-stat__value" id="statPending">—</div>
        <div class="staff-apps-stat__label">Pending Review</div>
    </div>
    <div class="staff-apps-stat">
        <div class="staff-apps-stat__value" id="statTotal">—</div>
        <div class="staff-apps-stat__label">In Queue</div>
    </div>
    <div class="staff-apps-stat staff-apps-stat--draft">
        <div class="staff-apps-stat__value" id="statDocs">—</div>
        <div class="staff-apps-stat__label">Needs Documents</div>
    </div>
    <div class="staff-apps-stat staff-apps-stat--active">
        <div class="staff-apps-stat__value" id="statActive">—</div>
        <div class="staff-apps-stat__label">Document Complete</div>
    </div>
</div>

<div class="staff-apps-note" role="note">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><strong>Maker-Checker separation applies.</strong> You cannot approve applications you personally submitted. Verify barangay assignment and supporting documents before activation.</span>
</div>

<div class="staff-apps-card">
    <div class="staff-apps-card__toolbar">
        <div class="staff-apps-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" id="bhwApprovalSearch" class="staff-apps-search__input" placeholder="Search applicant, barangay, email…" aria-label="Search applications">
        </div>
        <select id="bhwApprovalStatusFilter" class="staff-apps-filter" aria-label="Filter by status">
            <option value="all">All in queue</option>
            <option value="pending_approval">Pending approval</option>
            <option value="requires_documents">Requires documents</option>
        </select>
        <span class="staff-apps-card__count" id="bhwApprovalCount">Loading…</span>
    </div>
    <div class="staff-apps-table-wrap">
        <table class="staff-apps-table" id="bhwApprovalTable">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Barangay</th>
                    <th>Appointment</th>
                    <th>Documents</th>
                    <th>Submitted By</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="bhwApprovalBody">
                <tr><td colspan="8"><div class="staff-apps-loading"><div class="staff-apps-loading__spinner" role="status"></div>Loading queue…</div></td></tr>
            </tbody>
        </table>
    </div>
</div>

</article>

<div id="bhwReviewModal" class="admin-modal-overlay mc-staff-modal staff-approval-modal" style="display:none;" role="dialog" aria-modal="true">
    <div class="mc-card admin-modal-dialog admin-modal-dialog--wide">
        <div class="admin-modal-header">
            <div>
                <h3 class="admin-modal-title" id="bhwReviewTitle">Review BHW Application</h3>
                <p class="admin-modal-subtitle">Complete the approval checklist after verifying documents and identity.</p>
            </div>
            <button type="button" class="admin-modal-close" id="bhwReviewClose" aria-label="Close">&times;</button>
        </div>
        <div id="bhwReviewContent"></div>
        <div class="bhw-checklist" id="bhwChecklist">
            <h4 class="admin-form-section-title">Approval Checklist</h4>
            <label class="bhw-check-item"><input type="checkbox" id="check_identity"> Identity verified</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_barangay"> Barangay assignment confirmed</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_appointment"> Appointment letter or resolution verified</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_government_id"> Government-issued ID verified</label>
            <label class="bhw-check-item"><input type="checkbox" id="check_cho"> CHO endorsement verified <span class="text-muted">(if applicable)</span></label>
            <label class="bhw-check-item"><input type="checkbox" id="check_no_duplicate"> No duplicate BHW record exists</label>
        </div>
        <p id="bhwReviewError" class="admin-form-error"></p>
        <div class="admin-modal-actions">
            <button type="button" class="mc-btn mc-btn--outline" id="bhwRequestDocsBtn">Request Additional Documents</button>
            <button type="button" class="mc-btn mc-btn--outline bhw-btn-reject" id="bhwRejectBtn">Reject</button>
            <button type="button" class="mc-btn mc-btn--primary" id="bhwApproveBtn" disabled>Approve &amp; Activate</button>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.1">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-forms.css?v=1.0">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-bhw-applications.css?v=1.1">
<script src="<?= ASSET_BASE ?>/assets/js/admin-staff-applications.js?v=1.1"></script>
<script>
window.MC_BHW_APPROVAL = {
    api: <?= json_encode(ASSET_BASE . '/app/api/superadmin/bhw_approvals.php') ?>,
    currentUserId: <?= (int) ($_SESSION['user_id'] ?? 0) ?>
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/superadmin-bhw-approvals.js?v=1.1"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
