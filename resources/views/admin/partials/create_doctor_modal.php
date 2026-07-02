<?php
$create_doctor_modal_id = $create_doctor_modal_id ?? 'createDoctorModal';
$create_doctor_form_id = $create_doctor_form_id ?? 'createDoctorForm';
$create_doctor_api = $create_doctor_api ?? (ASSET_BASE . '/app/api/admin/create_doctor.php');
$create_doctor_show_role = $create_doctor_show_role ?? false;
$create_doctor_open_on_load = !empty($_GET['create']);
$create_doctor_submit_label = $create_doctor_submit_label ?? ($create_doctor_show_role ? 'Create Account' : 'Submit Application');
$create_doctor_application_mode = strpos((string) $create_doctor_api, 'doctor_applications') !== false;
$prc_portal_url = 'https://verification.prc.gov.ph/';
?>
<div id="<?= htmlspecialchars($create_doctor_modal_id) ?>"
     class="admin-modal-overlay mc-staff-modal"
     style="display: <?= $create_doctor_open_on_load ? 'flex' : 'none' ?>; pointer-events: <?= $create_doctor_open_on_load ? 'auto' : 'none' ?>;"
     role="dialog"
     aria-modal="true"
     aria-labelledby="<?= htmlspecialchars($create_doctor_modal_id) ?>Title">
    <div class="mc-card admin-modal-dialog admin-modal-dialog--doctor">
        <div class="admin-modal-header">
            <div>
                <h3 id="<?= htmlspecialchars($create_doctor_modal_id) ?>Title" class="admin-modal-title">
                    Create Doctor Application
                </h3>
                <p class="admin-modal-subtitle">Verify PRC credentials via the official portal, upload supporting documents, and submit for Super Administrator approval.</p>
            </div>
            <button type="button" class="admin-modal-close" data-close-modal="<?= htmlspecialchars($create_doctor_modal_id) ?>" aria-label="Close">&times;</button>
        </div>

        <form id="<?= htmlspecialchars($create_doctor_form_id) ?>" class="mc-staff-form" novalidate>
            <input type="hidden" name="application_id" id="<?= htmlspecialchars($create_doctor_form_id) ?>ApplicationId" value="">

            <section class="mc-form-section" id="<?= htmlspecialchars($create_doctor_form_id) ?>DoctorSections">
                <h4 class="mc-form-section__title">Personal Information</h4>
                <div class="mc-form-grid mc-form-grid--3">
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>FirstName">First Name</label>
                        <input type="text" name="first_name" id="<?= htmlspecialchars($create_doctor_form_id) ?>FirstName" required class="mc-field__input doctor-required" autocomplete="given-name" placeholder="Juan">
                        <p class="mc-field__error"></p>
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>MiddleName">Middle Name <span class="mc-optional">(optional)</span></label>
                        <input type="text" name="middle_name" id="<?= htmlspecialchars($create_doctor_form_id) ?>MiddleName" class="mc-field__input" autocomplete="additional-name" placeholder="Santos">
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>LastName">Last Name</label>
                        <input type="text" name="last_name" id="<?= htmlspecialchars($create_doctor_form_id) ?>LastName" required class="mc-field__input doctor-required" autocomplete="family-name" placeholder="Dela Cruz">
                        <p class="mc-field__error"></p>
                    </div>
                </div>
                <div class="mc-form-grid mc-form-grid--1">
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>Birthdate">Date of Birth</label>
                        <input type="date" name="birthdate" id="<?= htmlspecialchars($create_doctor_form_id) ?>Birthdate" required class="mc-field__input doctor-required" max="<?= date('Y-m-d') ?>">
                        <p class="mc-field__hint">Required for PRC license verification on the official portal.</p>
                        <p class="mc-field__error"></p>
                    </div>
                </div>

                <h4 class="mc-form-section__title" style="margin-top:24px;">Professional Information</h4>
                <div class="mc-form-grid mc-form-grid--1">
                    <div class="mc-field" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcGroup">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>PrcInput">PRC License Number</label>
                        <input type="text" name="prc_license_number" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcInput"
                               class="mc-field__input doctor-required" autocomplete="off" placeholder="e.g. 0123456"
                               pattern="[A-Za-z0-9\-]{5,20}" title="5–20 letters, numbers, or hyphens">
                        <p class="mc-field__error"></p>
                    </div>
                </div>

                <div class="prc-verification-panel" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcPanel">
                    <div class="prc-verification-panel__head">
                        <div>
                            <strong class="prc-verification-panel__title">PRC Verification</strong>
                            <p class="admin-form-hint" style="margin: 4px 0 0;">Manual verification via the official PRC portal — not automated.</p>
                        </div>
                        <div class="prc-verification-status" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcStatus" aria-live="polite">
                            <span class="prc-status-dot prc-status-dot--pending"></span>
                            <span id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcStatusText">Not Verified</span>
                        </div>
                    </div>
                    <button type="button" class="mc-btn mc-btn--outline prc-verify-btn" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcVerifyBtn">
                        Verify PRC License
                    </button>
                    <p class="admin-form-hint">Opens the official PRC Verification Portal in a new tab. MEDCONNECT does not scrape or automate verification.</p>

                    <details class="prc-guide-card">
                        <summary>Verification Steps</summary>
                        <ol class="prc-guide-list">
                            <li>Open the official <a href="<?= htmlspecialchars($prc_portal_url) ?>" target="_blank" rel="noopener noreferrer">PRC Verification Portal</a>.</li>
                            <li>Enter the doctor's:
                                <ul>
                                    <li>First Name</li>
                                    <li>Last Name</li>
                                    <li>Birthdate</li>
                                    <li>PRC License Number</li>
                                </ul>
                            </li>
                            <li>Confirm on the portal:
                                <ul class="prc-checklist">
                                    <li>Name matches</li>
                                    <li>Profession = Physician</li>
                                    <li>License Status = Active</li>
                                    <li>License is not expired</li>
                                </ul>
                            </li>
                            <li>Return to MEDCONNECT and confirm verification below.</li>
                        </ol>
                    </details>

                    <label class="prc-confirm-check">
                        <input type="checkbox" name="prc_verification_confirmed" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcConfirm" value="1">
                        <span>I have personally verified this doctor's PRC License using the official PRC Verification Portal.</span>
                    </label>

                    <p class="prc-success-note" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcSuccess" hidden>
                        PRC verification confirmed. Upload documents and submit the application for Super Administrator approval.
                    </p>
                </div>

                <div class="mc-form-grid">
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>Specialization">Specialization</label>
                        <input type="text" name="specialization" id="<?= htmlspecialchars($create_doctor_form_id) ?>Specialization" required class="mc-field__input doctor-required" placeholder="e.g. Internal Medicine">
                        <p class="mc-field__error"></p>
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>Facility">Affiliated Facility <span class="mc-optional">(optional)</span></label>
                        <input type="text" name="facility" id="<?= htmlspecialchars($create_doctor_form_id) ?>Facility" class="mc-field__input" placeholder="City Health Office">
                    </div>
                </div>

                <h4 class="mc-form-section__title" style="margin-top:24px;">Supporting Documents</h4>
                <p class="mc-field__hint" style="margin-bottom:14px;">Required before submission: PRC ID and Government-issued ID. Hospital/Clinic ID is optional.</p>
                <div class="bhw-doc-upload-grid" id="<?= htmlspecialchars($create_doctor_form_id) ?>DocUploads">
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>DocPrc">PRC ID <span class="mc-optional">(required)</span></label>
                        <input type="file" id="<?= htmlspecialchars($create_doctor_form_id) ?>DocPrc" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mc-field__input">
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>DocGov">Government-issued ID <span class="mc-optional">(required)</span></label>
                        <input type="file" id="<?= htmlspecialchars($create_doctor_form_id) ?>DocGov" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mc-field__input">
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>DocFacility">Hospital / Clinic ID <span class="mc-optional">(optional)</span></label>
                        <input type="file" id="<?= htmlspecialchars($create_doctor_form_id) ?>DocFacility" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mc-field__input">
                    </div>
                </div>
                <ul id="<?= htmlspecialchars($create_doctor_form_id) ?>DocList" class="bhw-doc-list"></ul>

                <h4 class="mc-form-section__title" style="margin-top:24px;">Contact Information</h4>
                <div class="mc-form-grid">
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>Email">Email Address</label>
                        <input type="email" name="email" id="<?= htmlspecialchars($create_doctor_form_id) ?>Email" required class="mc-field__input doctor-required" autocomplete="email" placeholder="doctor@medconnect.local">
                        <p class="mc-field__hint">Used as the login username.</p>
                        <p class="mc-field__error"></p>
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>Phone">Mobile Number</label>
                        <input type="tel" name="phone" id="<?= htmlspecialchars($create_doctor_form_id) ?>Phone" required class="mc-field__input doctor-required" autocomplete="tel" placeholder="09171234567" pattern="^(09|\+639)\d{9}$">
                        <p class="mc-field__error"></p>
                    </div>
                </div>

                <h4 class="mc-form-section__title" style="margin-top:24px;">Account Credentials</h4>
                <div class="mc-form-grid">
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>Password">Password</label>
                        <input type="password" name="password" id="<?= htmlspecialchars($create_doctor_form_id) ?>Password" required minlength="12" class="mc-field__input doctor-required" autocomplete="new-password" placeholder="Create a strong password">
                        <p class="mc-field__error"></p>
                    </div>
                    <div class="mc-field">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>PasswordConfirm">Confirm Password</label>
                        <input type="password" id="<?= htmlspecialchars($create_doctor_form_id) ?>PasswordConfirm" required minlength="12" class="mc-field__input doctor-required" autocomplete="new-password" placeholder="Re-enter password">
                        <p class="mc-field__error"></p>
                    </div>
                </div>
            </section>

            <?php if ($create_doctor_show_role): ?>
            <div class="mc-field" style="margin-top:16px;">
                <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>RoleSelect">Account Role</label>
                <select name="role" id="<?= htmlspecialchars($create_doctor_form_id) ?>RoleSelect" class="mc-field__input">
                    <option value="provider" selected>Doctor / Healthcare Provider</option>
                    <option value="admin">Administrator</option>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="role" value="provider">
            <?php endif; ?>

            <p id="<?= htmlspecialchars($create_doctor_form_id) ?>BlockMsg" class="mc-form-alert mc-form-alert--warn"></p>
            <p id="<?= htmlspecialchars($create_doctor_form_id) ?>Error" class="mc-form-alert mc-form-alert--error"></p>

            <div class="admin-modal-actions">
                <button type="button" class="mc-btn mc-btn--outline" data-close-modal="<?= htmlspecialchars($create_doctor_modal_id) ?>">Cancel</button>
                <?php if ($create_doctor_application_mode): ?>
                <button type="button" class="mc-btn mc-btn--outline" id="<?= htmlspecialchars($create_doctor_form_id) ?>SaveDraft">Save Draft</button>
                <?php endif; ?>
                <button type="submit" class="mc-btn mc-btn--primary" id="<?= htmlspecialchars($create_doctor_form_id) ?>Submit" disabled><?= htmlspecialchars($create_doctor_submit_label) ?></button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-forms.css?v=1.0">
<script src="<?= ASSET_BASE ?>/assets/js/admin-staff-form-utils.js?v=1.0"></script>

<script>
(function () {
    const modalId = <?= json_encode($create_doctor_modal_id) ?>;
    const formId = <?= json_encode($create_doctor_form_id) ?>;
    const apiUrl = <?= json_encode($create_doctor_api) ?>;
    const prcPortalUrl = <?= json_encode($prc_portal_url) ?>;
    const submitLabel = <?= json_encode($create_doctor_submit_label) ?>;
    const applicationMode = <?= $create_doctor_application_mode ? 'true' : 'false' ?>;
    const staffCreateApi = <?= json_encode(ASSET_BASE . '/app/api/admin/create_staff.php') ?>;
    const assetBase = <?= json_encode(ASSET_BASE) ?>;

    const modal = document.getElementById(modalId);
    const form = document.getElementById(formId);
    if (!modal || !form) return;

    const errorEl = document.getElementById(formId + 'Error');
    const blockMsg = document.getElementById(formId + 'BlockMsg');
    const submitBtn = document.getElementById(formId + 'Submit');
    const saveDraftBtn = document.getElementById(formId + 'SaveDraft');
    const applicationIdInput = document.getElementById(formId + 'ApplicationId');
    const docList = document.getElementById(formId + 'DocList');
    const roleSelect = document.getElementById(formId + 'RoleSelect');
    const doctorSections = document.getElementById(formId + 'DoctorSections');
    const prcPanel = document.getElementById(formId + 'PrcPanel');
    const prcGroup = document.getElementById(formId + 'PrcGroup');
    const prcInput = document.getElementById(formId + 'PrcInput');
    const prcConfirm = document.getElementById(formId + 'PrcConfirm');
    const prcVerifyBtn = document.getElementById(formId + 'PrcVerifyBtn');
    const prcStatus = document.getElementById(formId + 'PrcStatus');
    const prcStatusText = document.getElementById(formId + 'PrcStatusText');
    const prcSuccess = document.getElementById(formId + 'PrcSuccess');
    const birthdateInput = document.getElementById(formId + 'Birthdate');
    const passwordInput = document.getElementById(formId + 'Password');
    const passwordConfirm = document.getElementById(formId + 'PasswordConfirm');
    const emailInput = document.getElementById(formId + 'Email');
    const phoneInput = document.getElementById(formId + 'Phone');
    const utils = window.MCStaffForm || {};

    if (utils.wrapPasswordInput && passwordInput) {
        utils.wrapPasswordInput(passwordInput, { minLength: 12 });
    }
    if (utils.initPasswordConfirm && passwordInput && passwordConfirm) {
        utils.initPasswordConfirm(passwordInput, passwordConfirm);
    }

    const doctorFields = form.querySelectorAll('.doctor-required');
    const prcIdentityFields = [
        document.getElementById(formId + 'FirstName'),
        document.getElementById(formId + 'LastName'),
        birthdateInput,
        prcInput
    ].filter(Boolean);

    function isDoctorRole() {
        return !roleSelect || roleSelect.value === 'provider';
    }

    function setPrcStatus(verified) {
        if (!prcStatus || !prcStatusText) return;
        prcStatus.classList.toggle('is-verified', verified);
        prcStatusText.textContent = verified ? 'PRC VERIFIED' : 'Not Verified';
        const dot = prcStatus.querySelector('.prc-status-dot');
        if (dot) {
            dot.classList.toggle('prc-status-dot--verified', verified);
            dot.classList.toggle('prc-status-dot--pending', !verified);
        }
        if (prcSuccess) prcSuccess.hidden = !verified;
    }

    function resetPrcConfirmation() {
        if (prcConfirm) prcConfirm.checked = false;
        setPrcStatus(false);
    }

    function allDoctorFieldsFilled() {
        if (!isDoctorRole()) return true;
        return Array.from(doctorFields).every(function (el) {
            return String(el.value || '').trim() !== '';
        });
    }

    function prcConfirmed() {
        return isDoctorRole() && prcConfirm && prcConfirm.checked;
    }

    function credentialsValid() {
        if (!isDoctorRole()) return true;
        if (!passwordInput || !passwordConfirm) return false;
        if (!utils.passwordsMatch || !utils.validatePasswordStrength) {
            return passwordInput.value.length >= 12;
        }
        return utils.passwordsMatch(passwordInput, passwordConfirm)
            && utils.validatePasswordStrength(passwordInput.value, 12);
    }

    function updateSubmitState() {
        const doctor = isDoctorRole();
        if (doctorSections) doctorSections.style.display = doctor ? '' : 'none';
        if (prcPanel) prcPanel.style.display = doctor ? '' : 'none';
        if (prcGroup) prcGroup.style.display = doctor ? '' : 'none';

        doctorFields.forEach(function (el) {
            el.required = doctor;
        });

        if (!doctor) {
            if (blockMsg) utils.showFormAlert(blockMsg, '', 'warn');
            if (submitBtn) submitBtn.disabled = false;
            return;
        }

        const ready = allDoctorFieldsFilled() && prcConfirmed() && credentialsValid();
        if (submitBtn) submitBtn.disabled = !ready;
        if (blockMsg) {
            utils.showFormAlert(
                blockMsg,
                ready ? '' : 'Complete PRC verification, all required fields, matching passwords, and supporting documents before submitting.',
                'warn'
            );
        }
        setPrcStatus(prcConfirmed());
    }

    function syncPrcField() {
        resetPrcConfirmation();
        updateSubmitState();
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', syncPrcField);
    }

    if (prcVerifyBtn) {
        prcVerifyBtn.addEventListener('click', function () {
            window.open(prcPortalUrl, '_blank', 'noopener,noreferrer');
        });
    }

    if (prcConfirm) {
        prcConfirm.addEventListener('change', function () {
            if (prcConfirm.checked && !allDoctorFieldsFilled()) {
                prcConfirm.checked = false;
                utils.showFormAlert(errorEl, 'Complete all required fields (including birthdate and PRC license number) before confirming verification.', 'error');
                updateSubmitState();
                return;
            }
            utils.showFormAlert(errorEl, '', 'error');
            updateSubmitState();
        });
    }

    prcIdentityFields.forEach(function (el) {
        el.addEventListener('input', function () {
            if (prcConfirm && prcConfirm.checked) {
                resetPrcConfirmation();
            }
            updateSubmitState();
        });
    });

    form.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (prcIdentityFields.indexOf(el) !== -1) return;
        el.addEventListener('input', updateSubmitState);
        el.addEventListener('change', updateSubmitState);
    });

    window.openCreateDoctorModal = function () {
        utils.showFormAlert(errorEl, '', 'error');
        form.reset();
        if (applicationIdInput) applicationIdInput.value = '';
        if (docList) docList.innerHTML = '';
        resetPrcConfirmation();
        syncPrcField();
        modal.style.display = 'flex';
        modal.style.pointerEvents = 'auto';
    };

    function closeModal() {
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
    }

    document.querySelectorAll('[data-close-modal="' + modalId + '"]').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    document.querySelectorAll('[data-open-create-doctor]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openCreateDoctorModal();
        });
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        utils.showFormAlert(errorEl, '', 'error');

        if (isDoctorRole()) {
            if (!prcConfirmed()) {
                utils.showFormAlert(blockMsg, 'Confirm PRC verification before submitting.', 'warn');
                return;
            }
            if (emailInput && utils.validateEmail && !utils.validateEmail(emailInput.value)) {
                utils.setFieldError(emailInput, 'Enter a valid email address.');
                return;
            }
            if (phoneInput && utils.validatePhone && !utils.validatePhone(phoneInput.value)) {
                utils.setFieldError(phoneInput, 'Use format 09XXXXXXXXX or +639XXXXXXXXX.');
                return;
            }
            if (!credentialsValid()) {
                utils.setFieldError(passwordConfirm, 'Passwords must match and meet all strength requirements.');
                return;
            }
        }

        utils.setFormLoading(form, true, submitBtn, applicationMode ? 'Submitting application...' : 'Creating account...');

        try {
            if (applicationMode && isDoctorRole()) {
                let appId = applicationIdInput ? applicationIdInput.value : '';
                if (!appId) {
                    appId = await saveDraft(false, true);
                    if (!appId) return;
                } else {
                    const fd = new FormData(form);
                    const saveRes = await fetch(apiUrl + '?action=save_draft', { method: 'POST', body: fd, credentials: 'same-origin' });
                    const saveJson = await saveRes.json();
                    if (!saveJson.success) {
                        utils.showFormAlert(errorEl, saveJson.message || 'Could not save application.', 'error');
                        return;
                    }
                    await uploadPendingDocs(appId);
                }

                const submitFd = new FormData();
                submitFd.append('application_id', appId);
                const res = await fetch(apiUrl + '?action=submit', { method: 'POST', body: submitFd, credentials: 'same-origin' });
                const data = await res.json();
                if (data.success) {
                    const qs = window.location.pathname.indexOf('doctor_applications') >= 0
                        ? '?submitted=1'
                        : '?role=provider&submitted=1';
                    window.location.href = window.location.pathname + qs;
                    return;
                }
                if (errorEl) {
                    utils.showFormAlert(errorEl, data.message || 'Could not submit application.', 'error');
                }
                return;
            }

            const res = await fetch(isDoctorRole() ? apiUrl : staffCreateApi, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.success) {
                window.location.href = window.location.pathname + '?created=1';
                return;
            }
            if (errorEl) {
                utils.showFormAlert(errorEl, data.message || 'Could not create doctor account.', 'error');
            }
        } catch (err) {
            utils.showFormAlert(errorEl, 'Network error. Please try again.', 'error');
        } finally {
            utils.setFormLoading(form, false, submitBtn);
            updateSubmitState();
        }
    });

    async function saveDraft(redirect, quiet) {
        utils.showFormAlert(errorEl, '', 'error');
        const fd = new FormData(form);
        if (!quiet) utils.setFormLoading(form, true, saveDraftBtn, 'Saving draft...');
        try {
        const res = await fetch(apiUrl + '?action=save_draft', { method: 'POST', body: fd, credentials: 'same-origin' });
        const json = await res.json();
        if (!json.success) {
            utils.showFormAlert(errorEl, json.message || 'Could not save draft.', 'error');
            return null;
        }
        if (applicationIdInput) applicationIdInput.value = json.application_id;
        await uploadPendingDocs(json.application_id);
        if (redirect) {
            var appsUrl = (document.body.dataset.portal === 'superadmin')
                ? (assetBase + '/views/superadmin/doctor_approvals.php?saved=1')
                : (assetBase + '/views/admin/doctor_applications.php?saved=1');
            window.location.href = appsUrl;
        }
        return json.application_id;
        } finally {
            if (!quiet) utils.setFormLoading(form, false, saveDraftBtn);
        }
    }

    async function uploadPendingDocs(appId) {
        const uploads = [
            [formId + 'DocPrc', 'prc_id'],
            [formId + 'DocGov', 'government_id'],
            [formId + 'DocFacility', 'facility_id'],
        ];
        for (let i = 0; i < uploads.length; i++) {
            const input = document.getElementById(uploads[i][0]);
            if (!input || !input.files || !input.files[0]) continue;
            const fd = new FormData();
            fd.append('application_id', appId);
            fd.append('document_type', uploads[i][1]);
            fd.append('document', input.files[0]);
            await fetch(apiUrl + '?action=upload_document', { method: 'POST', body: fd, credentials: 'same-origin' });
        }
    }

    if (saveDraftBtn) {
        saveDraftBtn.addEventListener('click', function () { saveDraft(true); });
    }

    syncPrcField();
})();
</script>

<style>
.admin-modal-dialog--doctor {
    width: min(680px, 100%);
    max-height: min(92vh, 900px);
    overflow-y: auto;
}
.admin-modal-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--mc-navy-deep, #0f172a);
    margin: 0;
}
.admin-modal-subtitle {
    font-size: 13px;
    margin: 6px 0 0;
}
.admin-form-section-title {
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--mc-aqua-medium, #0891b2);
    margin: 20px 0 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--mc-border-thin, #e2e8f0);
}
.admin-form-section-title:first-child { margin-top: 0; }
.admin-form-grid--3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.admin-form-hint {
    font-size: 12px;
    color: #64748b;
    margin: 6px 0 0;
}
.prc-verification-panel {
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 16px;
}
.prc-verification-panel__head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.prc-verification-panel__title {
    font-size: 14px;
    color: var(--mc-navy-deep, #0f172a);
}
.prc-verification-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    padding: 6px 10px;
    border-radius: 999px;
    background: #fff;
    border: 1px solid #e2e8f0;
}
.prc-verification-status.is-verified {
    color: #15803d;
    border-color: #bbf7d0;
    background: #f0fdf4;
}
.prc-status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
.prc-status-dot--pending { background: #94a3b8; }
.prc-status-dot--verified { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.25); }
.prc-verify-btn { width: 100%; justify-content: center; margin-bottom: 8px; }
.prc-guide-card {
    margin: 12px 0;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #fff;
    overflow: hidden;
}
.prc-guide-card summary {
    cursor: pointer;
    padding: 12px 14px;
    font-weight: 700;
    font-size: 13px;
    color: var(--mc-navy-deep, #0f172a);
    list-style: none;
}
.prc-guide-card summary::-webkit-details-marker { display: none; }
.prc-guide-list {
    margin: 0;
    padding: 0 14px 14px 32px;
    font-size: 13px;
    color: #475569;
    line-height: 1.55;
}
.prc-guide-list ul { margin: 6px 0; padding-left: 18px; }
.prc-checklist li { list-style: none; position: relative; padding-left: 18px; }
.prc-checklist li::before { content: '✓'; position: absolute; left: 0; color: #16a34a; font-weight: 700; }
.prc-confirm-check {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 13px;
    color: #334155;
    cursor: pointer;
    margin-top: 12px;
}
.prc-confirm-check input { margin-top: 3px; flex-shrink: 0; }
.prc-success-note {
    margin: 12px 0 0;
    padding: 10px 12px;
    border-radius: 10px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #15803d;
    font-size: 13px;
    font-weight: 600;
}
.admin-form-warning {
    color: #b45309;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 13px;
    margin-bottom: 12px;
}
@media (max-width: 640px) {
    .admin-form-grid--3 { grid-template-columns: 1fr; }
}
.bhw-doc-upload-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 12px;
}
.bhw-doc-list {
    list-style: none;
    margin: 0 0 16px;
    padding: 0;
    font-size: 13px;
    color: #475569;
}
.bhw-doc-list li {
    padding: 6px 0;
    border-bottom: 1px solid #f1f5f9;
}
</style>
<?php if ($create_doctor_application_mode): ?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-bhw-applications.css?v=1.1">
<?php endif; ?>
