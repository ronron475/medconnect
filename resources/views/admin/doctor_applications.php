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
require_once BASE_PATH . '/app/includes/doctor_application_schema.php';
require_once __DIR__ . '/_portal_access.php';

doctor_application_ensure_schema($pdo);

$page_title = 'Doctor Applications';
$show_submitted = isset($_GET['submitted']);
$show_saved = isset($_GET['saved']);

require_once __DIR__ . '/partials/layout_open.php';
?>

<article class="staff-apps-page staff-apps-page--doctor">

<?php if ($show_submitted): ?>
<div class="staff-apps-flash staff-apps-flash--success" role="status">
    <div class="staff-apps-flash__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
    </div>
    <div>
        <p class="staff-apps-flash__title">Application submitted successfully</p>
        <p class="staff-apps-flash__text">A Super Administrator will review the application before the Doctor account is activated.</p>
    </div>
</div>
<?php endif; ?>

<?php if ($show_saved): ?>
<div class="staff-apps-flash staff-apps-flash--success" role="status">
    <div class="staff-apps-flash__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
    </div>
    <div>
        <p class="staff-apps-flash__title">Draft saved</p>
        <p class="staff-apps-flash__text">Your progress has been saved. You can continue editing and submit when ready.</p>
    </div>
</div>
<?php endif; ?>

<header class="staff-apps-hero">
    <div class="staff-apps-hero__content">
        <span class="staff-apps-hero__eyebrow">Administration · Maker-Checker Workflow</span>
        <h1 class="staff-apps-hero__title">Doctor Account Applications</h1>
        <p class="staff-apps-hero__desc">Prepare Doctor account applications, verify PRC licenses via the official portal, upload supporting documents, and submit for Super Administrator approval.</p>
    </div>
    <div class="staff-apps-hero__actions">
        <button type="button" class="mc-btn mc-btn--primary" id="doctorOpenCreateBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create Doctor Application
        </button>
    </div>
</header>

<div class="staff-apps-stats" id="doctorAppStats" aria-live="polite">
    <div class="staff-apps-stat">
        <div class="staff-apps-stat__value" id="statTotal">—</div>
        <div class="staff-apps-stat__label">Total Applications</div>
    </div>
    <div class="staff-apps-stat staff-apps-stat--draft">
        <div class="staff-apps-stat__value" id="statDraft">—</div>
        <div class="staff-apps-stat__label">Drafts</div>
    </div>
    <div class="staff-apps-stat staff-apps-stat--pending">
        <div class="staff-apps-stat__value" id="statPending">—</div>
        <div class="staff-apps-stat__label">Pending Approval</div>
    </div>
    <div class="staff-apps-stat staff-apps-stat--active">
        <div class="staff-apps-stat__value" id="statActive">—</div>
        <div class="staff-apps-stat__label">Approved / Active</div>
    </div>
</div>

<div class="staff-apps-note" role="note">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><strong>You cannot activate Doctor accounts directly.</strong> After submission, a Super Administrator must review PRC verification, documents, and approve the application before the account becomes active.</span>
</div>

<div class="staff-apps-card">
    <div class="staff-apps-card__toolbar">
        <div class="staff-apps-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" id="doctorAppSearch" class="staff-apps-search__input" placeholder="Search by name, email, PRC, specialization…" aria-label="Search applications">
        </div>
        <select id="doctorAppStatusFilter" class="staff-apps-filter" aria-label="Filter by status">
            <option value="all">All statuses</option>
            <option value="draft">Draft</option>
            <option value="pending_approval">Pending Approval</option>
            <option value="requires_documents">Requires Documents</option>
            <option value="rejected">Rejected</option>
            <option value="active">Active</option>
        </select>
        <span class="staff-apps-card__count" id="doctorAppCount">Loading…</span>
    </div>
    <div class="staff-apps-table-wrap">
        <table class="staff-apps-table" id="doctorAppsTable">
            <thead>
                <tr>
                    <th>Doctor</th>
                    <th>PRC License</th>
                    <th>Specialization</th>
                    <th>Hospital / Clinic</th>
                    <th>Documents</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="doctorAppsBody">
                <tr><td colspan="8"><div class="staff-apps-loading"><div class="staff-apps-loading__spinner" role="status"></div>Loading applications…</div></td></tr>
            </tbody>
        </table>
    </div>
</div>

</article>

<?php
$create_doctor_modal_id = 'doctorAppCreateModal';
$create_doctor_form_id = 'doctorAppCreateForm';
$create_doctor_api = ASSET_BASE . '/app/api/admin/doctor_applications.php';
$create_doctor_show_role = false;
$create_doctor_submit_label = 'Submit Application';
require __DIR__ . '/partials/create_doctor_modal.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.0">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-bhw-applications.css?v=1.1">
<script src="<?= ASSET_BASE ?>/assets/js/admin-staff-applications.js?v=1.0"></script>
<script>
window.MC_DOCTOR_APP = {
    api: <?= json_encode(ASSET_BASE . '/app/api/admin/doctor_applications.php') ?>,
    assetBase: <?= json_encode(ASSET_BASE) ?>
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-doctor-applications.js?v=1.1"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
