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
require_once BASE_PATH . '/app/includes/bhw_application_schema.php';
require_once BASE_PATH . '/app/includes/portal_paths.php';
require_once __DIR__ . '/_portal_access.php';

bhw_application_ensure_schema($pdo);

$hub_kind = 'bhw';
$hub_base = 'bhw_applications.php';
$hub_tab = $_GET['tab'] ?? 'all';
$allowed_tabs = ['all', 'drafts', 'pending', 'active', 'rejected', 'archived'];
if (!in_array($hub_tab, $allowed_tabs, true)) {
    $hub_tab = 'all';
}

$show_accounts_panel = in_array($hub_tab, ['active', 'archived'], true);
$show_applications_panel = !$show_accounts_panel;

$tab_status_map = [
    'all'      => 'all',
    'drafts'   => 'draft',
    'pending'  => 'pending_approval',
    'rejected' => 'rejected',
];
$initial_app_status = $tab_status_map[$hub_tab] ?? 'all';

$page_title = 'BHW Management';
$show_submitted = isset($_GET['submitted']);
$show_saved = isset($_GET['saved']);
$is_superadmin_checker = portal_is_superadmin_shell() || portal_is_superadmin();
$show_approved = isset($_GET['approved']);
$show_rejected = isset($_GET['rejected']);
if ($is_superadmin_checker) {
    $page_title = 'Barangay Health Workers';
}

require_once __DIR__ . '/partials/layout_open.php';
?>

<article class="staff-apps-page staff-apps-page--bhw">

<?php if ($show_submitted): ?>
<div class="staff-apps-flash staff-apps-flash--success" role="status">
    <div class="staff-apps-flash__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
    </div>
    <div>
        <p class="staff-apps-flash__title">Invitation sent</p>
        <p class="staff-apps-flash__text">The BHW must activate their account, set a password, and complete their profile before Super Administrator review.</p>
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
        <span class="staff-apps-hero__eyebrow"><?= $is_superadmin_checker ? 'Super Administration · Maker-Checker Review' : 'User Management · Maker-Checker Workflow' ?></span>
        <h1 class="staff-apps-hero__title"><?= $is_superadmin_checker ? 'Barangay Health Workers' : 'BHW Management' ?></h1>
        <p class="staff-apps-hero__desc"><?= $is_superadmin_checker
            ? 'Review Barangay Health Worker applications completed by invitees and manage approved accounts. Use the Pending Approval tab for Super Administrator review.'
            : 'Invite Barangay Health Workers, attach institutional documents, and track activation until Super Administrator approval.' ?></p>
    </div>
    <?php if (!$is_superadmin_checker): ?>
    <div class="staff-apps-hero__actions">
        <button type="button" class="mc-btn mc-btn--primary" id="bhwOpenCreateBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create BHW Invite
        </button>
    </div>
    <?php endif; ?>
</header>

<?php
$hub_views_base = portal_views_base();
require __DIR__ . '/partials/staff_hub_tabs.php';
?>

<?php if ($show_applications_panel): ?>
<div class="staff-apps-stats" id="bhwAppStats" aria-live="polite">
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
    <?php if ($is_superadmin_checker): ?>
    <span><strong>Maker-Checker separation applies.</strong> You cannot approve applications you personally submitted. Verify barangay assignment and supporting documents before activation.</span>
    <?php else: ?>
    <span><strong>You invite — the BHW completes.</strong> Enter assignment details and the appointment letter, then send an invite. The BHW creates their own password and uploads their Government ID. A Super Administrator gives final approval.</span>
    <?php endif; ?>
</div>

<div class="staff-apps-card">
    <div class="staff-apps-card__toolbar">
        <div class="staff-apps-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" id="bhwAppSearch" class="staff-apps-search__input" placeholder="Search by name, email, barangay…" aria-label="Search applications">
        </div>
        <select id="bhwAppStatusFilter" class="staff-apps-filter" aria-label="Filter by status">
            <option value="all">All statuses</option>
            <option value="draft">Draft</option>
            <option value="invited">Invited</option>
            <option value="onboarding">Onboarding</option>
            <option value="pending_approval">Pending Approval</option>
            <option value="requires_documents">Requires Documents</option>
            <option value="rejected">Rejected</option>
            <option value="active">Active</option>
        </select>
        <span class="staff-apps-card__count" id="bhwAppCount"></span>
    </div>
    <div class="staff-apps-table-wrap">
        <table class="staff-apps-table" id="bhwAppsTable">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Barangay</th>
                    <th>Appointment</th>
                    <th>Documents</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="bhwAppsBody"></tbody>
        </table>
    </div>
</div>

<?php else: ?>

<?php require __DIR__ . '/partials/staff_accounts_panel.php'; ?>

<?php endif; ?>

</article>

<div id="bhwAppModal" class="admin-modal-overlay mc-staff-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="bhwModalTitle">
    <div class="mc-card admin-modal-dialog admin-modal-dialog--wide">
        <div class="admin-modal-header">
            <div>
                <h3 class="admin-modal-title" id="bhwModalTitle">Invite Barangay Health Worker</h3>
                <p class="admin-modal-subtitle">Enter basic assignment details and upload the appointment letter. The BHW will set their own password and upload personal documents.</p>
            </div>
            <button type="button" class="admin-modal-close" id="bhwModalClose" aria-label="Close">&times;</button>
        </div>
        <form id="bhwAppForm" class="mc-staff-form admin-modal-body" novalidate>
            <input type="hidden" name="application_id" id="bhwApplicationId" value="">

            <section class="mc-form-section">
                <h4 class="mc-form-section__title">Invite Contact</h4>
                <div class="mc-form-grid mc-form-grid--3">
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwFirstName">First Name</label>
                        <input type="text" name="first_name" id="bhwFirstName" required class="mc-field__input" autocomplete="given-name" placeholder="Maria">
                        <p class="mc-field__error"></p>
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwMiddleName">Middle Name <span class="mc-optional">(optional)</span></label>
                        <input type="text" name="middle_name" id="bhwMiddleName" class="mc-field__input" autocomplete="additional-name">
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwLastName">Last Name</label>
                        <input type="text" name="last_name" id="bhwLastName" required class="mc-field__input" autocomplete="family-name" placeholder="Santos">
                        <p class="mc-field__error"></p>
                    </div>
                </div>
            </section>

            <section class="mc-form-section">
                <h4 class="mc-form-section__title">Assignment Information</h4>
                <div class="mc-form-grid">
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwBarangaySelect">Assigned Barangay</label>
                        <select name="barangay_id" id="bhwBarangaySelect" required class="mc-field__input"><option value="">Select barangay…</option></select>
                        <p class="mc-field__error"></p>
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwAppointmentDate">Appointment Date</label>
                        <input type="date" name="appointment_date" id="bhwAppointmentDate" required class="mc-field__input">
                        <p class="mc-field__hint">Select the BHW appointment date.</p>
                        <p class="mc-field__error"></p>
                    </div>
                </div>
            </section>

            <section class="mc-form-section">
                <h4 class="mc-form-section__title">Contact Information</h4>
                <div class="mc-form-grid">
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwEmail">Email Address</label>
                        <input type="email" name="email" id="bhwEmail" required class="mc-field__input" autocomplete="email" placeholder="bhw@medconnect.local">
                        <p class="mc-field__hint">Invite and login email. The BHW sets their own password.</p>
                        <p class="mc-field__error"></p>
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwPhone">Mobile Number</label>
                        <input type="tel" name="phone" id="bhwPhone" required class="mc-field__input" autocomplete="tel" placeholder="09171234567" pattern="^(09|\+639)\d{9}$">
                        <p class="mc-field__error"></p>
                    </div>
                </div>
            </section>

            <section class="mc-form-section">
                <h4 class="mc-form-section__title">Institutional Documents</h4>
                <p class="mc-field__hint" style="margin-bottom:14px;">Required before invite: Barangay Appointment Letter / Resolution. CHO Endorsement is optional. Government ID is uploaded by the BHW during onboarding.</p>
                <div class="bhw-doc-upload-grid">
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwDocAppointment">Appointment Letter / Resolution</label>
                        <input type="file" id="bhwDocAppointment" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mc-field__input">
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwDocCho">CHO Endorsement <span class="mc-optional">(optional)</span></label>
                        <input type="file" id="bhwDocCho" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mc-field__input">
                    </div>
                </div>
                <ul id="bhwDocList" class="bhw-doc-list"></ul>
            </section>

            <p id="bhwRejectionNote" class="mc-form-alert mc-form-alert--warn"></p>
            <p id="bhwDocsRequestNote" class="mc-form-alert mc-form-alert--warn"></p>
            <p id="bhwFormError" class="mc-form-alert mc-form-alert--error"></p>

            <div class="admin-modal-actions">
                <button type="button" class="mc-btn mc-btn--outline" id="bhwModalCancel">Cancel</button>
                <button type="button" class="mc-btn mc-btn--outline" id="bhwSaveDraftBtn">Save Draft</button>
                <button type="button" class="mc-btn mc-btn--outline" id="bhwResendInviteBtn" style="display:none;">Resend Invite</button>
                <button type="submit" class="mc-btn mc-btn--primary" id="bhwSubmitBtn">Send Invite</button>
            </div>
        </form>
    </div>
</div>

<?php if ($is_superadmin_checker): ?>
<?php require __DIR__ . '/partials/bhw_review_modal.php'; ?>
<?php endif; ?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.4">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-bhw-applications.css?v=1.3">
<script src="<?= ASSET_BASE ?>/assets/js/admin-staff-applications.js?v=1.2"></script>
<script>
window.MC_BHW_APP = {
    api: <?= json_encode(ASSET_BASE . '/app/api/admin/bhw_applications.php') ?>,
    assetBase: <?= json_encode(ASSET_BASE) ?>,
    initialTab: <?= json_encode($hub_tab) ?>,
    initialStatus: <?= json_encode($initial_app_status) ?>,
    showApplications: <?= $show_applications_panel ? 'true' : 'false' ?>,
    checkerMode: <?= $is_superadmin_checker ? 'true' : 'false' ?>
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-bhw-applications.js?v=2.0"></script>
<?php if ($is_superadmin_checker): ?>
<script>
window.MC_BHW_APPROVAL = {
    api: <?= json_encode(ASSET_BASE . '/app/api/superadmin/bhw_approvals.php') ?>,
    currentUserId: <?= (int) ($_SESSION['user_id'] ?? 0) ?>,
    hubMode: true
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/superadmin-bhw-approvals.js?v=1.3"></script>
<?php endif; ?>

<?php
if ($show_accounts_panel) {
    $account_status_api = ASSET_BASE . '/app/api/admin/account_status.php';
    require __DIR__ . '/partials/account_status_modal.php';
}
?>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
