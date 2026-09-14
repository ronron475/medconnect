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

        <form id="<?= htmlspecialchars($create_doctor_form_id) ?>" class="mc-staff-form admin-modal-body" novalidate>
            <input type="hidden" name="application_id" id="<?= htmlspecialchars($create_doctor_form_id) ?>ApplicationId" value="">

            <section class="mc-form-section" id="<?= htmlspecialchars($create_doctor_form_id) ?>DoctorSections">
                <h4 class="mc-form-section__title">Identity Information</h4>
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

                <h4 class="mc-form-section__title" style="margin-top:24px;">Professional / PRC Information</h4>

                <div class="prc-verification-panel" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcPanel">
                    <div class="prc-verification-panel__head">
                        <p class="prc-verification-panel__eyebrow">Credential check</p>
                        <strong class="prc-verification-panel__title" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcPanelTitle">PRC Verification</strong>
                        <div class="prc-verification-status" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcStatus" aria-live="polite">
                            <span class="prc-status-dot prc-status-dot--pending" aria-hidden="true"></span>
                            <span class="prc-verification-status__label" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcStatusText">Not Verified</span>
                        </div>
                    </div>

                    <div class="mc-field prc-license-field" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcGroup">
                        <label class="mc-field__label" for="<?= htmlspecialchars($create_doctor_form_id) ?>PrcInput">PRC License Number</label>
                        <input type="text" name="prc_license_number" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcInput"
                               class="mc-field__input doctor-required prc-license-field__input" autocomplete="off" placeholder="e.g. 0123456"
                               pattern="[A-Za-z0-9\-]{5,20}" title="5–20 letters, numbers, or hyphens"
                               inputmode="text" spellcheck="false">
                        <p class="mc-field__hint">Enter the license number exactly as shown on the PRC ID.</p>
                        <p class="mc-field__error"></p>
                    </div>

                    <button type="button" class="mc-btn mc-btn--primary prc-verify-btn" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcVerifyBtn">
                        <svg class="prc-verify-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15 3 21 3 21 9"/>
                            <line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                        <span class="prc-verify-btn__text">Open PRC Verification Portal</span>
                    </button>
                    <p class="prc-verify-btn__hint">Opens the official PRC Verification Portal in a new tab.</p>

                    <div class="prc-guide-card">
                        <h5 class="prc-guide-card__title">Verification Steps</h5>
                        <ol class="prc-steps">
                            <li class="prc-step">
                                <span class="prc-step__num" aria-hidden="true">01</span>
                                <div class="prc-step__body">
                                    <p class="prc-step__heading">Open the official PRC Verification Portal</p>
                                    <p class="prc-step__text">Use the button above to open <a href="<?= htmlspecialchars($prc_portal_url) ?>" target="_blank" rel="noopener noreferrer">verification.prc.gov.ph</a>.</p>
                                </div>
                            </li>
                            <li class="prc-step">
                                <span class="prc-step__num" aria-hidden="true">02</span>
                                <div class="prc-step__body">
                                    <p class="prc-step__heading">Enter the doctor's information</p>
                                    <ul class="prc-step__bullets">
                                        <li>First Name</li>
                                        <li>Last Name</li>
                                        <li>Birthdate</li>
                                        <li>PRC License Number</li>
                                    </ul>
                                </div>
                            </li>
                            <li class="prc-step">
                                <span class="prc-step__num" aria-hidden="true">03</span>
                                <div class="prc-step__body">
                                    <p class="prc-step__heading">Confirm the information</p>
                                    <ul class="prc-step__checks">
                                        <li>Name matches</li>
                                        <li>Profession = Physician</li>
                                        <li>License Status = Active</li>
                                        <li>License is not expired</li>
                                    </ul>
                                </div>
                            </li>
                            <li class="prc-step">
                                <span class="prc-step__num" aria-hidden="true">04</span>
                                <div class="prc-step__body">
                                    <p class="prc-step__heading">Return to MEDCONNECT</p>
                                    <p class="prc-step__text">Confirm the verification below once you have checked the portal.</p>
                                </div>
                            </li>
                        </ol>
                    </div>

                    <div class="prc-confirm-block">
                        <label class="prc-confirm-check" for="<?= htmlspecialchars($create_doctor_form_id) ?>PrcConfirm">
                            <input type="checkbox" name="prc_verification_confirmed" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcConfirm" value="1">
                            <span class="prc-confirm-check__text">I have personally verified this doctor's PRC license on the official portal.</span>
                        </label>
                        <p class="mc-field__error" id="<?= htmlspecialchars($create_doctor_form_id) ?>PrcConfirmError"></p>
                    </div>

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
                <p class="mc-field__hint" style="margin-bottom:14px;">PRC ID and government-issued ID are required before submission. Hospital/clinic ID is optional.</p>
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
                <div class="mc-form-grid mc-form-grid--1 mc-credentials-grid">
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
    const prcConfirmError = document.getElementById(formId + 'PrcConfirmError');
    const prcVerifyBtn = document.getElementById(formId + 'PrcVerifyBtn');
    const prcStatus = document.getElementById(formId + 'PrcStatus');
    const prcStatusText = document.getElementById(formId + 'PrcStatusText');
    const prcSuccess = document.getElementById(formId + 'PrcSuccess');
    const birthdateInput = document.getElementById(formId + 'Birthdate');
    const passwordInput = document.getElementById(formId + 'Password');
    const passwordConfirm = document.getElementById(formId + 'PasswordConfirm');
    const emailInput = document.getElementById(formId + 'Email');
    const phoneInput = document.getElementById(formId + 'Phone');
    const firstNameInput = document.getElementById(formId + 'FirstName');
    const lastNameInput = document.getElementById(formId + 'LastName');
    const specializationInput = document.getElementById(formId + 'Specialization');
    const utils = window.MCStaffForm || {};

    if (utils.bindFieldErrorClear) utils.bindFieldErrorClear(form);

    if (utils.wrapPasswordInput && passwordInput) {
        utils.wrapPasswordInput(passwordInput, { minLength: 12 });
    }
    if (utils.initPasswordConfirm && passwordInput && passwordConfirm) {
        utils.initPasswordConfirm(passwordInput, passwordConfirm);
    }
    if (utils.enhanceFileInputsIn) {
        utils.enhanceFileInputsIn(form);
    }

    const doctorFields = form.querySelectorAll('.doctor-required');
    const prcIdentityFields = [
        firstNameInput,
        lastNameInput,
        birthdateInput,
        prcInput
    ].filter(Boolean);

    const fieldByName = {
        first_name: firstNameInput,
        last_name: lastNameInput,
        birthdate: birthdateInput,
        prc_license_number: prcInput,
        specialization: specializationInput,
        email: emailInput,
        phone: phoneInput,
        password: passwordInput,
    };

    function setPrcConfirmError(message) {
        if (!prcConfirmError) return;
        prcConfirmError.textContent = message || '';
        prcConfirmError.classList.toggle('is-visible', !!message);
    }

    function applyBackendErrors(errors) {
        if (!errors || typeof errors !== 'object') return false;
        var keys = Object.keys(errors);
        if (!keys.length) return false;
        keys.forEach(function (key) {
            if (key === 'prc_verification') {
                setPrcConfirmError(errors[key]);
                return;
            }
            var input = fieldByName[key];
            if (input) utils.setFieldError(input, errors[key]);
        });
        return true;
    }

    function validateDoctorFormFields() {
        var valid = true;
        utils.clearFieldErrors(form);
        setPrcConfirmError('');
        utils.showFormAlert(errorEl, '', 'error');

        if (!isDoctorRole()) return true;

        var firstName = firstNameInput ? String(firstNameInput.value || '').trim() : '';
        if (!firstName) {
            utils.setFieldError(firstNameInput, 'First name is required.');
            valid = false;
        }
        var lastName = lastNameInput ? String(lastNameInput.value || '').trim() : '';
        if (!lastName) {
            utils.setFieldError(lastNameInput, 'Last name is required.');
            valid = false;
        }
        if (birthdateInput && !String(birthdateInput.value || '').trim()) {
            utils.setFieldError(birthdateInput, 'Birthdate is required for PRC verification.');
            valid = false;
        }
        if (prcInput && !String(prcInput.value || '').trim()) {
            utils.setFieldError(prcInput, 'PRC license number is required.');
            valid = false;
        }
        if (specializationInput && !String(specializationInput.value || '').trim()) {
            utils.setFieldError(specializationInput, 'Specialization is required.');
            valid = false;
        }
        if (emailInput) {
            if (!String(emailInput.value || '').trim()) {
                utils.setFieldError(emailInput, 'Email address is required.');
                valid = false;
            } else if (utils.validateEmail && !utils.validateEmail(emailInput.value)) {
                utils.setFieldError(emailInput, 'Enter a valid email address.');
                valid = false;
            }
        }
        if (phoneInput) {
            if (!String(phoneInput.value || '').trim()) {
                utils.setFieldError(phoneInput, 'Mobile number is required.');
                valid = false;
            } else if (utils.validatePhone && !utils.validatePhone(phoneInput.value)) {
                utils.setFieldError(phoneInput, 'Use format 09XXXXXXXXX or +639XXXXXXXXX.');
                valid = false;
            }
        }
        var pwd = passwordInput ? String(passwordInput.value || '') : '';
        var confirmPwd = passwordConfirm ? String(passwordConfirm.value || '') : '';
        if (!hasExistingApplication() && !pwd) {
            utils.setFieldError(passwordInput, 'Password is required.');
            valid = false;
        } else if (pwd && utils.validatePasswordStrength && !utils.validatePasswordStrength(pwd, 12)) {
            utils.setFieldError(passwordInput, 'Password must meet all strength requirements.');
            valid = false;
        }
        if (pwd || confirmPwd) {
            if (passwordConfirm && passwordInput && !utils.passwordsMatch(passwordInput, passwordConfirm)) {
                utils.setFieldError(passwordConfirm, 'Passwords do not match.');
                valid = false;
            }
        }
        if (!prcConfirmed()) {
            setPrcConfirmError('Confirm PRC verification before submitting.');
            valid = false;
        }

        if (!valid && errorEl) {
            utils.showFormAlert(errorEl, 'Please correct the highlighted fields.', 'error');
        }
        return valid;
    }

    function isDoctorRole() {
        return !roleSelect || roleSelect.value === 'provider';
    }

    function setPrcStatus(verified) {
        if (!prcStatus || !prcStatusText) return;
        prcStatus.classList.toggle('is-verified', verified);
        prcStatusText.textContent = verified ? 'Verified' : 'Not Verified';
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

    function hasExistingApplication() {
        return applicationIdInput && String(applicationIdInput.value || '').trim() !== '';
    }

    function allDoctorFieldsFilled() {
        if (!isDoctorRole()) return true;
        return Array.from(doctorFields).every(function (el) {
            if (hasExistingApplication() && (el === passwordInput || el === passwordConfirm)) {
                return true;
            }
            return String(el.value || '').trim() !== '';
        });
    }

    function prcConfirmed() {
        return isDoctorRole() && prcConfirm && prcConfirm.checked;
    }

    function credentialsValid() {
        if (!isDoctorRole()) return true;
        if (!passwordInput || !passwordConfirm) return false;
        var pwd = String(passwordInput.value || '');
        var confirmPwd = String(passwordConfirm.value || '');
        if (hasExistingApplication() && pwd === '' && confirmPwd === '') {
            return true;
        }
        if (!utils.passwordsMatch || !utils.validatePasswordStrength) {
            return pwd.length >= 12;
        }
        return utils.passwordsMatch(passwordInput, passwordConfirm)
            && utils.validatePasswordStrength(pwd, 12);
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
                ready ? '' : 'Complete required fields, PRC verification, and matching passwords before submitting.',
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
            setPrcConfirmError('');
            if (prcConfirm.checked && !allDoctorFieldsFilled()) {
                prcConfirm.checked = false;
                setPrcConfirmError('Complete all required identity and PRC fields before confirming verification.');
                updateSubmitState();
                return;
            }
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
        utils.clearFieldErrors(form);
        setPrcConfirmError('');
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
        setPrcConfirmError('');

        if (isDoctorRole()) {
            if (!validateDoctorFormFields()) return;
        }

        var fd = utils.buildFormData ? utils.buildFormData(form) : new FormData(form);
        utils.setFormLoading(form, true, submitBtn, applicationMode ? 'Submitting application...' : 'Creating account...');

        try {
            if (applicationMode && isDoctorRole()) {
                let appId = applicationIdInput ? applicationIdInput.value : '';
                if (!appId) {
                    appId = await saveDraft(false, true, fd);
                    if (!appId) return;
                } else {
                    const saveRes = await fetch(apiUrl + '?action=save_draft', { method: 'POST', body: fd, credentials: 'same-origin' });
                    const saveJson = await saveRes.json();
                    if (!saveJson.success) {
                        if (!applyBackendErrors(saveJson.errors)) {
                            utils.showFormAlert(errorEl, saveJson.message || 'Could not save application.', 'error');
                        }
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
                if (!applyBackendErrors(data.errors)) {
                    utils.showFormAlert(errorEl, data.message || 'Could not submit application.', 'error');
                }
                return;
            }

            const res = await fetch(isDoctorRole() ? apiUrl : staffCreateApi, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.success) {
                window.location.href = window.location.pathname + '?created=1';
                return;
            }
            if (!applyBackendErrors(data.errors)) {
                utils.showFormAlert(errorEl, data.message || 'Could not create doctor account.', 'error');
            }
        } catch (err) {
            utils.showFormAlert(errorEl, 'Network error. Please try again.', 'error');
        } finally {
            utils.setFormLoading(form, false, submitBtn);
            updateSubmitState();
        }
    });

    async function saveDraft(redirect, quiet, prebuiltFd) {
        utils.showFormAlert(errorEl, '', 'error');
        const fd = prebuiltFd || (utils.buildFormData ? utils.buildFormData(form) : new FormData(form));
        if (!quiet) utils.setFormLoading(form, true, saveDraftBtn, 'Saving draft...');
        try {
        const res = await fetch(apiUrl + '?action=save_draft', { method: 'POST', body: fd, credentials: 'same-origin' });
        const json = await res.json();
        if (!json.success) {
            if (!applyBackendErrors(json.errors)) {
                utils.showFormAlert(errorEl, json.message || 'Could not save draft.', 'error');
            }
            return null;
        }
        if (applicationIdInput) applicationIdInput.value = json.application_id;
        await uploadPendingDocs(json.application_id);
        if (redirect) {
            var appsUrl = (document.body.dataset.portal === 'superadmin')
                ? (assetBase + '/views/superadmin/doctor_applications.php?saved=1')
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
        saveDraftBtn.addEventListener('click', function () {
            utils.clearFieldErrors(form);
            saveDraft(true);
        });
    }

    syncPrcField();
})();
</script>

<style>
.admin-modal-dialog--doctor {
    width: min(680px, 100%);
    max-height: min(92dvh, 900px);
    max-height: min(92vh, 900px);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.admin-modal-dialog--doctor .admin-modal-body {
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    -webkit-overflow-scrolling: touch;
}
.mc-credentials-grid {
    max-width: 100%;
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

/* ── PRC Verification panel ── */
.prc-verification-panel {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 18px 16px 16px;
    margin: 4px 0 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    max-width: 100%;
    box-sizing: border-box;
}
.prc-verification-panel__head {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
    padding-bottom: 14px;
    border-bottom: 1px solid #e2e8f0;
}
.prc-verification-panel__eyebrow {
    margin: 0;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--mc-aqua-medium, #0891b2);
}
.prc-verification-panel__title {
    display: block;
    font-size: 20px;
    font-weight: 800;
    line-height: 1.25;
    color: var(--mc-navy-deep, #0f172a);
    letter-spacing: -0.01em;
}
.prc-verification-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 34px;
    padding: 7px 12px;
    border-radius: 999px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #9a3412;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.prc-verification-status.is-verified {
    background: #f0fdf4;
    border-color: #bbf7d0;
    color: #15803d;
}
.prc-status-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
}
.prc-status-dot--pending {
    background: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
}
.prc-status-dot--verified {
    background: #22c55e;
    box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.22);
}
.prc-verification-status__label {
    line-height: 1.2;
}

/* Credential input */
.prc-license-field {
    margin: 0;
}
.prc-license-field .mc-field__label {
    font-size: 13px;
    font-weight: 700;
    color: var(--mc-navy-deep, #0f172a);
    margin-bottom: 8px;
}
.prc-license-field__input {
    min-height: 48px;
    font-size: 16px;
    font-weight: 600;
    letter-spacing: 0.04em;
    font-variant-numeric: tabular-nums;
    border-radius: 12px;
    border: 1.5px solid #cbd5e1;
    background: #fff;
    padding: 12px 14px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.prc-license-field__input::placeholder {
    font-weight: 500;
    letter-spacing: 0;
    color: #94a3b8;
    opacity: 1;
}
.prc-license-field__input:hover {
    border-color: #94a3b8;
}
.prc-license-field__input:focus {
    outline: none;
    border-color: var(--mc-aqua-medium, #0891b2);
    box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.18);
}
.prc-license-field .mc-field__hint {
    margin-top: 8px;
    font-size: 12px;
    color: #64748b;
}

/* Primary portal action */
.prc-verify-btn {
    width: 100%;
    min-height: 48px;
    justify-content: center;
    gap: 10px;
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    border-radius: 12px;
    padding: 12px 16px;
}
.prc-verify-btn__icon {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}
.prc-verify-btn__text {
    text-align: center;
    line-height: 1.3;
}
.prc-verify-btn:focus-visible {
    outline: 2px solid var(--mc-aqua-medium, #0891b2);
    outline-offset: 2px;
}
.prc-verify-btn__hint {
    margin: -8px 0 0;
    font-size: 12px;
    color: #64748b;
    line-height: 1.4;
}

/* Numbered verification steps */
.prc-guide-card {
    margin: 0;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #fff;
    padding: 14px 14px 6px;
    overflow: hidden;
}
.prc-guide-card__title {
    margin: 0 0 12px;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--mc-navy-deep, #0f172a);
}
.prc-steps {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0;
}
.prc-step {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr);
    gap: 12px;
    padding: 12px 0;
    border-top: 1px solid #f1f5f9;
}
.prc-step:first-of-type {
    border-top: none;
    padding-top: 4px;
}
.prc-step__num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #ecfeff;
    border: 1px solid #a5f3fc;
    color: var(--mc-aqua-medium, #0e7490);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.02em;
    line-height: 1;
}
.prc-step__body {
    min-width: 0;
}
.prc-step__heading {
    margin: 0 0 4px;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.35;
    color: var(--mc-navy-deep, #0f172a);
}
.prc-step__text {
    margin: 0;
    font-size: 13px;
    line-height: 1.5;
    color: #475569;
}
.prc-step__text a {
    color: var(--mc-aqua-medium, #0891b2);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
    word-break: break-word;
}
.prc-step__bullets,
.prc-step__checks {
    list-style: none;
    margin: 6px 0 0;
    padding: 0;
    display: grid;
    gap: 4px;
}
.prc-step__bullets li,
.prc-step__checks li {
    position: relative;
    padding-left: 16px;
    font-size: 13px;
    line-height: 1.45;
    color: #475569;
}
.prc-step__bullets li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.55em;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #94a3b8;
}
.prc-step__checks li {
    padding-left: 20px;
}
.prc-step__checks li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.35em;
    width: 12px;
    height: 7px;
    border-left: 2px solid #16a34a;
    border-bottom: 2px solid #16a34a;
    transform: rotate(-45deg);
}

/* Confirmation */
.prc-confirm-block {
    margin: 0;
    padding: 12px;
    border-radius: 12px;
    background: #fff;
    border: 1px solid #e2e8f0;
}
.prc-confirm-check {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin: 0;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}
.prc-confirm-check input[type="checkbox"] {
    appearance: none;
    -webkit-appearance: none;
    width: 22px;
    height: 22px;
    margin: 1px 0 0;
    flex-shrink: 0;
    border: 2px solid #94a3b8;
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    position: relative;
    transition: border-color 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease;
}
.prc-confirm-check input[type="checkbox"]:hover {
    border-color: var(--mc-aqua-medium, #0891b2);
}
.prc-confirm-check input[type="checkbox"]:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.25);
    border-color: var(--mc-aqua-medium, #0891b2);
}
.prc-confirm-check input[type="checkbox"]:checked {
    background: var(--mc-aqua-medium, #0891b2);
    border-color: var(--mc-aqua-medium, #0891b2);
}
.prc-confirm-check input[type="checkbox"]:checked::after {
    content: '';
    position: absolute;
    left: 5px;
    top: 1px;
    width: 6px;
    height: 11px;
    border: solid #fff;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}
.prc-confirm-check__text {
    flex: 1;
    min-width: 0;
    font-size: 14px;
    line-height: 1.45;
    color: #334155;
    font-weight: 500;
}
.prc-confirm-block .mc-field__error {
    margin: 8px 0 0;
}
.prc-confirm-block .mc-field__error.is-visible {
    color: #dc2626;
    font-size: 12px;
    font-weight: 600;
}
.prc-success-note {
    margin: 0;
    padding: 12px 14px;
    border-radius: 10px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #15803d;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.45;
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
.bhw-doc-upload-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
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

@media (min-width: 641px) {
    .prc-verification-panel {
        padding: 20px;
        gap: 18px;
    }
    .prc-verification-panel__head {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        grid-template-areas:
            "eyebrow status"
            "title status";
        align-items: start;
        column-gap: 16px;
        row-gap: 6px;
    }
    .prc-verification-panel__eyebrow { grid-area: eyebrow; }
    .prc-verification-panel__title { grid-area: title; font-size: 22px; }
    .prc-verification-status {
        grid-area: status;
        align-self: center;
        font-size: 12px;
    }
    .prc-verify-btn {
        width: 100%;
    }
    .prc-guide-card {
        padding: 16px 16px 8px;
    }
    .prc-step__heading { font-size: 16px; }
    .prc-step__text,
    .prc-step__bullets li,
    .prc-step__checks li { font-size: 14px; }
    .prc-confirm-check__text { font-size: 15px; }
}

@media (max-width: 640px) {
    .admin-form-grid--3 { grid-template-columns: 1fr; }
    .prc-verification-panel {
        padding: 16px 14px;
        border-radius: 14px;
        gap: 14px;
    }
    .prc-verification-panel__title {
        font-size: 20px;
    }
    .prc-verification-status {
        font-size: 12px;
        max-width: 100%;
    }
    .prc-license-field__input {
        font-size: 16px; /* avoid iOS zoom */
    }
    .prc-verify-btn {
        width: 100%;
        font-size: 15px;
    }
    .prc-step {
        grid-template-columns: 32px minmax(0, 1fr);
        gap: 10px;
        padding: 11px 0;
    }
    .prc-step__num {
        width: 32px;
        height: 32px;
        font-size: 11px;
        border-radius: 8px;
    }
    .prc-step__heading {
        font-size: 15px;
    }
    .prc-step__text,
    .prc-step__bullets li,
    .prc-step__checks li {
        font-size: 13px;
    }
    .prc-confirm-block {
        padding: 12px 10px;
    }
    .prc-confirm-check {
        gap: 12px;
        min-height: 44px;
    }
    .prc-confirm-check__text {
        font-size: 14px;
    }
}

@media (max-width: 360px) {
    .prc-verification-panel {
        padding: 14px 12px;
    }
    .prc-verification-panel__title {
        font-size: 18px;
    }
    .prc-step__heading {
        font-size: 14px;
    }
}
</style>
<?php if ($create_doctor_application_mode): ?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-bhw-applications.css?v=1.1">
<?php endif; ?>
