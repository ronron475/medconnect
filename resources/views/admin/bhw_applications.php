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
require_once BASE_PATH . '/app/includes/barangays_bago.php';
require_once BASE_PATH . '/app/includes/portal_paths.php';
require_once BASE_PATH . '/app/core/BhwApplicationService.php';
require_once __DIR__ . '/_portal_access.php';

bhw_application_ensure_schema($pdo);

$hub_kind = 'bhw';
$hub_base = 'bhw_applications.php';
$hub_tab = 'all';
// Barangay-first hub: no All/Drafts/Pending/Active/Rejected/Archived tab bar.
$show_accounts_panel = false;
$show_applications_panel = true;
$initial_app_status = 'all';

/** @var list<array{id: int, name: string, city?: string}> */
$bhw_invite_barangays = [];
try {
    foreach (barangays_list_bago_city($pdo) as $row) {
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }
        $bhw_invite_barangays[] = [
            'id'   => $id,
            'name' => $name,
            'city' => (string) ($row['city'] ?? 'Bago City'),
        ];
    }
} catch (Throwable $e) {
    error_log('bhw_applications.php barangay preload failed: ' . $e->getMessage());
    $bhw_invite_barangays = [];
}

$page_title = 'BHW Management';
$show_submitted = isset($_GET['submitted']);
$show_saved = isset($_GET['saved']);
$is_superadmin_checker = portal_is_superadmin_shell() || portal_is_superadmin();
$show_approved = isset($_GET['approved']);
$show_rejected = isset($_GET['rejected']);
if ($is_superadmin_checker) {
    $page_title = 'BHW Applications';
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

<header class="staff-apps-hero staff-apps-hero--intro">
    <div class="staff-apps-hero__content">
        <p class="staff-apps-hero__desc"><?= $is_superadmin_checker
            ? 'Final Maker–Checker review for Barangay Health Worker applications. Open a barangay to verify documents and approve or reject pending applications. Only Super Administrators can approve or reject.'
            : 'Invite Barangay Health Workers by barangay. Select an assigned barangay when creating an invite; track each barangay’s BHW counts in real time.' ?></p>
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

<section class="bhw-hub" id="bhwBarangayHub" aria-labelledby="bhwHubTitle">
    <div class="bhw-hub__head">
        <div>
            <h2 class="bhw-hub__title" id="bhwHubTitle">Barangay list</h2>
            <p class="bhw-hub__sub">Select a barangay to view its BHWs. Counts update live when invites are sent or accounts change.</p>
        </div>
        <span class="bhw-hub__live" id="bhwHubLive" aria-live="polite">Updating…</span>
    </div>

    <div class="staff-apps-stats" id="bhwHubStats" aria-live="polite">
        <div class="staff-apps-stat">
            <div class="staff-apps-stat__value" id="bhwHubTotal">—</div>
            <div class="staff-apps-stat__label">Total</div>
        </div>
        <div class="staff-apps-stat staff-apps-stat--active">
            <div class="staff-apps-stat__value" id="bhwHubActive">—</div>
            <div class="staff-apps-stat__label">Active</div>
        </div>
        <div class="staff-apps-stat staff-apps-stat--pending">
            <div class="staff-apps-stat__value" id="bhwHubPending">—</div>
            <div class="staff-apps-stat__label">Pending Approval</div>
        </div>
        <div class="staff-apps-stat staff-apps-stat--draft">
            <div class="staff-apps-stat__value" id="bhwHubInactive">—</div>
            <div class="staff-apps-stat__label">Inactive / Deactivated</div>
        </div>
    </div>

    <div class="staff-apps-card" id="bhwHubListCard">
        <div class="staff-apps-card__toolbar">
            <div class="staff-apps-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" id="bhwBrgySearch" class="staff-apps-search__input" placeholder="Search barangay name…" aria-label="Search barangay name">
            </div>
            <span class="staff-apps-card__count" id="bhwBrgyCount"></span>
        </div>
        <div class="staff-apps-table-wrap">
            <table class="staff-apps-table" id="bhwBrgyTable">
                <thead>
                    <tr>
                        <th>Barangay</th>
                        <th>Total BHWs</th>
                        <th>Active</th>
                        <th>Pending Approval</th>
                        <th>Inactive / Deactivated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="bhwBrgyBody">
                    <tr><td colspan="6"><div class="mc-table-empty">Loading barangays…</div></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="staff-apps-card bhw-hub-detail" id="bhwHubDetail" hidden>
        <div class="bhw-hub-detail__toolbar">
            <button type="button" class="mc-btn mc-btn--outline mc-btn--sm" id="bhwHubBackBtn">← Back to barangays</button>
            <div>
                <h3 class="bhw-hub-detail__title" id="bhwHubDetailTitle">Barangay BHWs</h3>
                <p class="bhw-hub-detail__sub" id="bhwHubDetailSub"></p>
            </div>
        </div>
        <div class="staff-apps-table-wrap">
            <table class="staff-apps-table" id="bhwHubDetailTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Documents</th>
                        <th>Appointment</th>
                        <th>Approval</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="bhwHubDetailBody"></tbody>
            </table>
        </div>
    </div>
</section>

</article>

<div id="bhwAppModal" class="admin-modal-overlay mc-staff-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="bhwModalTitle">
    <div class="mc-card admin-modal-dialog admin-modal-dialog--wide bhw-invite-dialog">
        <div class="admin-modal-header bhw-invite-header">
            <div class="bhw-invite-header__text">
                <h3 class="admin-modal-title" id="bhwModalTitle">Invite Barangay Health Worker</h3>
                <p class="admin-modal-subtitle">Enter basic assignment details and upload the appointment letter. The BHW will set their own password and upload personal documents.</p>
            </div>
            <button type="button" class="admin-modal-close" id="bhwModalClose" aria-label="Close">&times;</button>
        </div>
        <form id="bhwAppForm" class="mc-staff-form bhw-invite-form" novalidate>
            <div class="admin-modal-body bhw-invite-scroll">
                <input type="hidden" name="application_id" id="bhwApplicationId" value="">

                <section class="mc-form-section">
                    <h4 class="mc-form-section__title">BHW Information</h4>
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
                    <div class="mc-form-grid bhw-invite-assign-grid">
                        <div class="mc-field mc-field--barangay">
                            <label class="mc-field__label" for="bhwBarangaySelect">Assigned Barangay</label>
                            <select name="barangay_id" id="bhwBarangaySelect" required class="mc-field__input" data-mc-select-anchored="1">
                                <option value="">Select barangay…</option>
                                <?php foreach ($bhw_invite_barangays as $brgy): ?>
                                <option value="<?= (int) $brgy['id'] ?>"><?= htmlspecialchars($brgy['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p id="bhwBarangayStatus" class="mc-field__hint" aria-live="polite"<?= $bhw_invite_barangays === [] ? '' : ' hidden' ?>><?= $bhw_invite_barangays === [] ? 'Loading barangays…' : '' ?></p>
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
                    <p class="bhw-docs-lead">Required before invite: Barangay Appointment Letter / Resolution. CHO Endorsement is optional. Government ID is uploaded by the BHW during onboarding.</p>
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
            </div>

            <div class="admin-modal-actions bhw-invite-footer">
                <button type="button" class="mc-btn mc-btn--outline" id="bhwModalCancel">Cancel</button>
                <button type="button" class="mc-btn mc-btn--outline" id="bhwSaveDraftBtn">Save Draft</button>
                <button type="button" class="mc-btn mc-btn--outline" id="bhwResendInviteBtn" style="display:none;">Resend Invite</button>
                <button type="submit" class="mc-btn mc-btn--primary" id="bhwSubmitBtn">Send Invite</button>
            </div>
        </form>
    </div>
</div>

<div id="bhwHubDocsModal" class="admin-modal-overlay bhw-hub-docs-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="bhwHubDocsTitle">
    <div class="mc-card admin-modal-dialog bhw-hub-docs-dialog">
        <div class="admin-modal-header">
            <div>
                <h3 class="admin-modal-title" id="bhwHubDocsTitle">Documents</h3>
                <p class="admin-modal-subtitle" id="bhwHubDocsSub">Uploaded files for this BHW</p>
            </div>
            <button type="button" class="admin-modal-close" id="bhwHubDocsClose" aria-label="Close">&times;</button>
        </div>
        <div class="admin-modal-body bhw-hub-docs-body">
            <ul class="bhw-doc-list" id="bhwHubDocsList"></ul>
            <p class="bhw-hub-docs-empty" id="bhwHubDocsEmpty" hidden>No documents uploaded for this BHW.</p>
        </div>
        <div class="admin-modal-actions">
            <button type="button" class="mc-btn mc-btn--outline" id="bhwHubDocsDone">Close</button>
        </div>
    </div>
</div>

<div id="bhwDocPreviewModal" class="bhw-doc-preview-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="bhwDocPreviewTitle">
    <div class="bhw-doc-preview-dialog">
        <div class="bhw-doc-preview-header">
            <div>
                <h3 class="bhw-doc-preview-title" id="bhwDocPreviewTitle">Document preview</h3>
                <p class="bhw-doc-preview-sub" id="bhwDocPreviewSub"></p>
            </div>
            <div class="bhw-doc-preview-actions">
                <a id="bhwDocPreviewDownload" class="mc-btn mc-btn--outline" href="#">Download</a>
                <button type="button" class="admin-modal-close" id="bhwDocPreviewClose" aria-label="Close preview">&times;</button>
            </div>
        </div>
        <div class="bhw-doc-preview-body" id="bhwDocPreviewBody"></div>
    </div>
</div>

<?php if ($is_superadmin_checker): ?>
<?php require __DIR__ . '/partials/bhw_review_modal.php'; ?>
<?php endif; ?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.4">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-bhw-applications.css?v=2.1">
<script src="<?= ASSET_BASE ?>/assets/js/admin-staff-applications.js?v=1.2"></script>
<script>
window.MC_BHW_APP = {
    api: <?= json_encode(ASSET_BASE . '/app/api/admin/bhw_applications.php') ?>,
    accountStatusApi: <?= json_encode(ASSET_BASE . '/app/api/admin/account_status.php') ?>,
    assetBase: <?= json_encode(ASSET_BASE) ?>,
    initialTab: <?= json_encode($hub_tab) ?>,
    initialStatus: <?= json_encode($initial_app_status) ?>,
    showApplications: <?= $show_applications_panel ? 'true' : 'false' ?>,
    checkerMode: <?= $is_superadmin_checker ? 'true' : 'false' ?>,
    canManageAccounts: <?= $is_superadmin_checker ? 'true' : 'false' ?>,
    statusGroups: <?= json_encode(BhwApplicationService::HUB_STATUS_GROUPS, JSON_UNESCAPED_UNICODE) ?>,
    barangays: <?= json_encode($bhw_invite_barangays, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-bhw-applications.js?v=3.2"></script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-bhw-barangay-hub.js?v=1.3"></script>
<?php if ($is_superadmin_checker): ?>
<script>
window.MC_BHW_APPROVAL = {
    api: <?= json_encode(ASSET_BASE . '/app/api/superadmin/bhw_approvals.php') ?>,
    currentUserId: <?= (int) ($_SESSION['user_id'] ?? 0) ?>,
    hubMode: true
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/superadmin-bhw-approvals.js?v=1.5"></script>
<?php
$account_status_api = ASSET_BASE . '/app/api/admin/account_status.php';
require __DIR__ . '/partials/account_status_modal.php';
endif;
?>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
