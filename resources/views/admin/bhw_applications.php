<?php
session_start();
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
require_once __DIR__ . '/_portal_access.php';

bhw_application_ensure_schema($pdo);

$page_title = 'BHW Applications';
$show_submitted = isset($_GET['submitted']);
$show_saved = isset($_GET['saved']);

require_once __DIR__ . '/partials/layout_open.php';
?>

<article class="staff-apps-page staff-apps-page--bhw">

<?php if ($show_submitted): ?>
<div class="staff-apps-flash staff-apps-flash--success" role="status">
    <div class="staff-apps-flash__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
    </div>
    <div>
        <p class="staff-apps-flash__title">Application submitted successfully</p>
        <p class="staff-apps-flash__text">A Super Administrator will review the application before the BHW account is activated.</p>
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
        <h1 class="staff-apps-hero__title">Barangay Health Worker Applications</h1>
        <p class="staff-apps-hero__desc">Prepare BHW account applications, upload supporting documents, and submit them for Super Administrator approval.</p>
    </div>
    <div class="staff-apps-hero__actions">
        <button type="button" class="mc-btn mc-btn--primary" id="bhwOpenCreateBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create BHW Application
        </button>
    </div>
</header>

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
    <span><strong>You cannot activate BHW accounts directly.</strong> After submission, a Super Administrator must review documents and approve the application before the account becomes active.</span>
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
            <option value="pending_approval">Pending Approval</option>
            <option value="requires_documents">Requires Documents</option>
            <option value="rejected">Rejected</option>
            <option value="active">Active</option>
        </select>
        <span class="staff-apps-card__count" id="bhwAppCount">Loading…</span>
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
            <tbody id="bhwAppsBody">
                <tr><td colspan="7"><div class="staff-apps-loading"><div class="staff-apps-loading__spinner" role="status"></div>Loading applications…</div></td></tr>
            </tbody>
        </table>
    </div>
</div>

</article>

<div id="bhwAppModal" class="admin-modal-overlay mc-staff-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="bhwModalTitle">
    <div class="mc-card admin-modal-dialog admin-modal-dialog--wide">
        <div class="admin-modal-header">
            <div>
                <h3 class="admin-modal-title" id="bhwModalTitle">Create BHW Application</h3>
                <p class="admin-modal-subtitle">Complete the form and upload supporting documents. Submit for Super Administrator approval.</p>
            </div>
            <button type="button" class="admin-modal-close" id="bhwModalClose" aria-label="Close">&times;</button>
        </div>
        <form id="bhwAppForm" class="mc-staff-form" novalidate>
            <input type="hidden" name="application_id" id="bhwApplicationId" value="">

            <section class="mc-form-section">
                <h4 class="mc-form-section__title">Personal Information</h4>
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
                        <p class="mc-field__hint">Date of barangay health worker appointment or resolution.</p>
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
                        <p class="mc-field__hint">Used as the login username.</p>
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
                <h4 class="mc-form-section__title">Account Credentials</h4>
                <div class="mc-form-grid">
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwPassword">Password</label>
                        <input type="password" name="password" id="bhwPassword" class="mc-field__input" minlength="12" autocomplete="new-password" placeholder="Create a strong password">
                        <p class="mc-field__error"></p>
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwPasswordConfirm">Confirm Password</label>
                        <input type="password" id="bhwPasswordConfirm" class="mc-field__input" minlength="12" autocomplete="new-password" placeholder="Re-enter password">
                        <p class="mc-field__error"></p>
                    </div>
                </div>
            </section>

            <section class="mc-form-section">
                <h4 class="mc-form-section__title">Supporting Documents</h4>
                <p class="mc-field__hint" style="margin-bottom:14px;">Required before submission: Barangay Appointment Letter / Resolution and Government-issued ID. CHO Endorsement is optional.</p>
                <div class="bhw-doc-upload-grid">
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwDocAppointment">Appointment Letter / Resolution</label>
                        <input type="file" id="bhwDocAppointment" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mc-field__input">
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="bhwDocGovId">Government-issued ID</label>
                        <input type="file" id="bhwDocGovId" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mc-field__input">
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
                <button type="submit" class="mc-btn mc-btn--primary" id="bhwSubmitBtn">Submit for Approval</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.0">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-forms.css?v=1.0">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-bhw-applications.css?v=1.1">
<script src="<?= ASSET_BASE ?>/assets/js/admin-staff-form-utils.js?v=1.0"></script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-staff-applications.js?v=1.0"></script>
<script>
window.MC_BHW_APP = {
    api: <?= json_encode(ASSET_BASE . '/app/api/admin/bhw_applications.php') ?>,
    assetBase: <?= json_encode(ASSET_BASE) ?>
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-bhw-applications.js?v=1.2"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
