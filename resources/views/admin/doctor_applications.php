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
require_once BASE_PATH . '/app/includes/portal_paths.php';
require_once __DIR__ . '/_portal_access.php';

doctor_application_ensure_schema($pdo);

$hub_kind = 'doctor';
$hub_base = 'doctor_applications.php';
$is_superadmin_portal = portal_is_superadmin_shell() || portal_is_superadmin();
$hub_show_queue_tab = $is_superadmin_portal;
$hub_tab = $_GET['tab'] ?? 'all';
$allowed_tabs = ['all', 'applications', 'pending', 'active', 'rejected', 'archived'];
if ($hub_show_queue_tab) {
    $allowed_tabs[] = 'queue';
}
if (!in_array($hub_tab, $allowed_tabs, true)) {
    $hub_tab = 'all';
}

$show_queue_panel = ($hub_tab === 'queue');
$show_accounts_panel = in_array($hub_tab, ['active', 'archived'], true);
$show_applications_panel = !$show_accounts_panel && !$show_queue_panel;

$tab_status_map = [
    'all'          => 'all',
    'applications' => 'all',
    'pending'      => 'pending_approval',
    'rejected'     => 'rejected',
];
$initial_app_status = $tab_status_map[$hub_tab] ?? 'all';

$page_title = 'Doctor Management';
if ($is_superadmin_portal) {
    $page_title = $show_queue_panel ? 'Doctors · Queue Monitoring' : 'Doctors';
}
$show_submitted = isset($_GET['submitted']);
$show_saved = isset($_GET['saved']);
$show_approved = isset($_GET['approved']);
$show_rejected = isset($_GET['rejected']);

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

<?php if ($show_approved): ?>
<div class="staff-apps-flash staff-apps-flash--success" role="status">
    <div class="staff-apps-flash__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
    </div>
    <div>
        <p class="staff-apps-flash__title">Doctor account approved</p>
        <p class="staff-apps-flash__text">The account is now active and the doctor may log in.</p>
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
        <span class="staff-apps-hero__eyebrow"><?= $is_superadmin_portal ? 'User Management · Doctor Operations' : 'User Management · Maker-Checker Workflow' ?></span>
        <h1 class="staff-apps-hero__title"><?= $is_superadmin_portal ? 'Doctors' : 'Doctor Management' ?></h1>
        <p class="staff-apps-hero__desc"><?php if ($show_queue_panel): ?>
            Monitor today's consultation queue — waiting, active, and completed patients across all providers.
        <?php elseif ($is_superadmin_portal): ?>
            Manage doctor applications, approved accounts, and live queue monitoring. Use the Pending Approval tab to review submissions from administrators.
        <?php else: ?>
            Manage doctor applications, PRC verification, supporting documents, and approved doctor accounts from one place.
        <?php endif; ?></p>
    </div>
    <?php if (!$is_superadmin_portal): ?>
    <div class="staff-apps-hero__actions">
        <button type="button" class="mc-btn mc-btn--primary" id="doctorOpenCreateBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create Doctor Application
        </button>
    </div>
    <?php endif; ?>
</header>

<?php
$hub_views_base = portal_views_base();
require __DIR__ . '/partials/staff_hub_tabs.php';
?>

<?php if ($show_applications_panel): ?>
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
    <?php if ($is_superadmin_portal): ?>
    <span><strong>Maker-Checker separation applies.</strong> You cannot approve applications you personally submitted. Complete the full checklist before activating any Doctor account.</span>
    <?php else: ?>
    <span><strong>You cannot activate Doctor accounts directly.</strong> After submission, a Super Administrator must review PRC verification, documents, and approve the application before the account becomes active.</span>
    <?php endif; ?>
</div>

<div class="staff-apps-card">
    <div class="staff-apps-card__toolbar">
        <div class="staff-apps-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" id="doctorAppSearch" class="staff-apps-search__input" placeholder="Search name, email, or PRC…" aria-label="Search applications">
        </div>
        <select id="doctorAppStatusFilter" class="staff-apps-filter" aria-label="Filter by status">
            <option value="all">All statuses</option>
            <option value="draft">Draft</option>
            <option value="pending_approval">Pending Approval</option>
            <option value="requires_documents">Requires Documents</option>
            <option value="rejected">Rejected</option>
            <option value="active">Active</option>
        </select>
        <span class="staff-apps-card__count" id="doctorAppCount"></span>
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
            <tbody id="doctorAppsBody"></tbody>
        </table>
    </div>
</div>

<?php elseif ($show_queue_panel): ?>

<?php
$queue_embedded = true;
require __DIR__ . '/partials/queue_monitoring_panel.php';
?>

<?php else: ?>

<?php require __DIR__ . '/partials/staff_accounts_panel.php'; ?>

<?php endif; ?>

</article>

<?php if ($is_superadmin_portal): ?>
<?php require __DIR__ . '/partials/doctor_review_modal.php'; ?>
<?php endif; ?>

<?php if (!$is_superadmin_portal): ?>
<?php
$create_doctor_modal_id = 'doctorAppCreateModal';
$create_doctor_form_id = 'doctorAppCreateForm';
$create_doctor_api = ASSET_BASE . '/app/api/admin/doctor_applications.php';
$create_doctor_show_role = false;
$create_doctor_submit_label = 'Submit Application';
require __DIR__ . '/partials/create_doctor_modal.php';
?>
<?php endif; ?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.3">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-bhw-applications.css?v=1.2">
<script src="<?= ASSET_BASE ?>/assets/js/admin-staff-applications.js?v=1.1"></script>
<script>
window.MC_DOCTOR_APP = {
    api: <?= json_encode(ASSET_BASE . '/app/api/admin/doctor_applications.php') ?>,
    assetBase: <?= json_encode(ASSET_BASE) ?>,
    initialTab: <?= json_encode($hub_tab) ?>,
    initialStatus: <?= json_encode($initial_app_status) ?>,
    showApplications: <?= $show_applications_panel ? 'true' : 'false' ?>,
    checkerMode: <?= $is_superadmin_portal ? 'true' : 'false' ?>
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-doctor-applications.js?v=1.3"></script>
<?php if ($is_superadmin_portal): ?>
<script>
window.MC_DOCTOR_APPROVAL = {
    api: <?= json_encode(ASSET_BASE . '/app/api/superadmin/doctor_approvals.php') ?>,
    currentUserId: <?= (int) ($_SESSION['user_id'] ?? 0) ?>,
    hubMode: true
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/superadmin-doctor-approvals.js?v=1.2"></script>
<?php endif; ?>

<?php if ($show_queue_panel): ?>
<?php $adminQueueLiveVer = (int) @filemtime(ASSETS_PATH . '/js/admin-queue-live.js'); ?>
<script src="<?= ASSET_BASE ?>/assets/js/admin-queue-live.js?v=<?= $adminQueueLiveVer ?>"></script>
<?php endif; ?>

<?php
if ($show_accounts_panel) {
    if (portal_is_superadmin()) {
        require __DIR__ . '/partials/doctor_verify_modal.php';
    }
    $account_status_api = ASSET_BASE . '/app/api/admin/account_status.php';
    require __DIR__ . '/partials/account_status_modal.php';
}
?>
<script>
(function () {
    document.querySelectorAll('.js-verify-doctor').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (typeof openDoctorVerifyModal === 'function') {
                openDoctorVerifyModal(btn.dataset.userId, btn.dataset.name || '', btn.dataset.prc || '');
            }
        });
    });
    document.querySelectorAll('.js-reject-doctor').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (typeof openDoctorRejectModal === 'function') {
                openDoctorRejectModal(btn.dataset.userId, btn.dataset.name || '');
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
