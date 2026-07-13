<?php
$page_title = 'Register Patient';
$bhw_current_file = 'patients/register.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$barangay_label = htmlspecialchars($bhw_barangay_name);

ob_start();
?>
(function () {
  var form = document.getElementById('bhwRegisterForm');
  if (!form) return;

  var BARANGAY = <?= json_encode($bhw_barangay_name) ?>;
  var patientIndex = { emails: {}, contacts: {} };
  var submitting = false;

  var fields = {
    first_name: document.getElementById('reg_first_name'),
    middle_name: document.getElementById('reg_middle_name'),
    last_name: document.getElementById('reg_last_name'),
    suffix: document.getElementById('reg_suffix'),
    date_of_birth: document.getElementById('reg_dob'),
    age: document.getElementById('reg_age'),
    gender: document.getElementById('reg_gender'),
    civil_status: document.getElementById('reg_civil_status'),
    contact_number: document.getElementById('reg_contact'),
    email: document.getElementById('reg_email'),
    address: document.getElementById('reg_address'),
    purok: document.getElementById('reg_purok'),
    blood_type: document.getElementById('reg_blood'),
    existing_conditions: document.getElementById('reg_conditions'),
    allergies: document.getElementById('reg_allergies'),
    medications: document.getElementById('reg_medications'),
    disabilities: document.getElementById('reg_disabilities'),
    pregnancy: document.getElementById('reg_pregnancy'),
    emergency_name: document.getElementById('reg_ec_name'),
    emergency_phone: document.getElementById('reg_ec_phone'),
    emergency_relation: document.getElementById('reg_ec_relation'),
    consent: document.getElementById('reg_consent'),
    terms: document.getElementById('reg_terms')
  };

  var sum = {
    avatar: document.getElementById('sum_avatar'),
    name: document.getElementById('sum_name'),
    id: document.getElementById('sum_id'),
    age: document.getElementById('sum_age'),
    gender: document.getElementById('sum_gender'),
    blood: document.getElementById('sum_blood'),
    contact: document.getElementById('sum_contact'),
    email: document.getElementById('sum_email'),
    barangay: document.getElementById('sum_barangay'),
    purok: document.getElementById('sum_purok'),
    regStatus: document.getElementById('sum_reg_status'),
    accountStatus: document.getElementById('sum_account_status')
  };

  var checklist = {
    personal: document.getElementById('chk_personal'),
    contact: document.getElementById('chk_contact'),
    medical: document.getElementById('chk_medical'),
    consent: document.getElementById('chk_consent'),
    ready: document.getElementById('chk_ready')
  };

  var pregnancyWrap = document.getElementById('reg_pregnancy_wrap');
  var submitBtn = document.getElementById('reg_submit_btn');

  function dash(v) {
    return v && String(v).trim() ? String(v).trim() : '—';
  }

  function initials(first, last) {
    return ((first || '?').charAt(0) + (last || '').charAt(0)).toUpperCase();
  }

  function fullName() {
    var parts = [fields.first_name.value, fields.middle_name.value, fields.last_name.value, fields.suffix.value]
      .map(function (s) { return (s || '').trim(); })
      .filter(Boolean);
    return parts.join(' ') || '—';
  }

  function computeAge(dob) {
    if (!dob) return '';
    var birth = new Date(dob + 'T00:00:00');
    if (isNaN(birth.getTime())) return '';
    var today = new Date();
    var age = today.getFullYear() - birth.getFullYear();
    var m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
    return age >= 0 ? String(age) : '';
  }

  function normalizePhone(v) {
    return String(v || '').replace(/\D/g, '');
  }

  function isValidPhMobile(v) {
    var n = normalizePhone(v);
    return /^09\d{9}$/.test(n);
  }

  function isValidEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || '').trim());
  }

  function passwordStrength(v) {
    var p = String(v || '');
    if (p.length < 8) return { ok: false, msg: 'Password must be at least 8 characters.' };
    var score = 0;
    if (/[a-z]/.test(p)) score++;
    if (/[A-Z]/.test(p)) score++;
    if (/\d/.test(p)) score++;
    if (/[^A-Za-z0-9]/.test(p)) score++;
    if (score < 2) return { ok: false, msg: 'Use a mix of letters, numbers, or symbols for a stronger password.' };
    return { ok: true, msg: '' };
  }

  function setFieldError(input, msg) {
    if (!input) return;
    var wrap = input.closest('.bhw-field');
    if (!wrap) return;
    wrap.classList.toggle('is-invalid', !!msg);
    var err = wrap.querySelector('.bhw-field-error');
    if (err) err.textContent = msg || '';
    if (msg) input.setAttribute('aria-invalid', 'true');
    else input.removeAttribute('aria-invalid');
  }

  function clearErrors() {
    form.querySelectorAll('.bhw-field.is-invalid').forEach(function (el) {
      el.classList.remove('is-invalid');
      var err = el.querySelector('.bhw-field-error');
      if (err) err.textContent = '';
    });
  }

  function validateField(input) {
    if (!input) return true;
    var name = input.name || input.id;
    var val = (input.value || '').trim();
    var ok = true;
    var msg = '';

    if (input === fields.first_name || input === fields.last_name) {
      if (!val) { ok = false; msg = 'This field is required.'; }
    } else if (input === fields.date_of_birth) {
      if (!val) { ok = false; msg = 'Date of birth is required.'; }
      else {
        var age = computeAge(val);
        if (age === '' || parseInt(age, 10) > 120) { ok = false; msg = 'Enter a valid date of birth.'; }
        else if (parseInt(age, 10) < 0) { ok = false; msg = 'Date of birth cannot be in the future.'; }
      }
    } else if (input === fields.gender) {
      if (!val) { ok = false; msg = 'Please select gender.'; }
    } else if (input === fields.contact_number) {
      if (!val) { ok = false; msg = 'Mobile number is required.'; }
      else if (!isValidPhMobile(val)) { ok = false; msg = 'Use Philippine format: 09XXXXXXXXX (11 digits).'; }
      else if (patientIndex.contacts[normalizePhone(val)]) { ok = false; msg = 'This contact number is already registered.'; }
    } else if (input === fields.email) {
      if (!val) { ok = false; msg = 'Email is required.'; }
      else if (!isValidEmail(val)) { ok = false; msg = 'Enter a valid email address.'; }
      else if (patientIndex.emails[val.toLowerCase()]) { ok = false; msg = 'This email is already registered.'; }
    }

    setFieldError(input, ok ? '' : msg);
    return ok;
  }

  function sectionPersonalDone() {
    return fields.first_name.value.trim() !== '' &&
      fields.last_name.value.trim() !== '' &&
      fields.date_of_birth.value !== '' &&
      fields.gender.value !== '';
  }

  function sectionContactDone() {
    return isValidPhMobile(fields.contact_number.value) && isValidEmail(fields.email.value);
  }

  function sectionMedicalDone() {
    return fields.blood_type.value.trim() !== '';
  }

  function sectionConsentDone() {
    return fields.consent.checked && fields.terms.checked;
  }

  function sectionReady() {
    return sectionPersonalDone() && sectionContactDone() && sectionMedicalDone() && sectionConsentDone();
  }

  function setChecklistItem(el, done) {
    if (!el) return;
    el.classList.toggle('is-done', done);
    el.classList.toggle('is-pending', !done);
    var icon = el.querySelector('.bhw-checklist-icon');
    if (icon) icon.textContent = done ? '✔' : '○';
  }

  function updateSummary() {
    var fn = fields.first_name.value.trim();
    var ln = fields.last_name.value.trim();
    var age = computeAge(fields.date_of_birth.value);

    if (fields.age) fields.age.value = age;

    sum.avatar.textContent = fn || ln ? initials(fn, ln) : '?';
    sum.name.textContent = fullName();
    sum.age.textContent = age ? age + ' yrs' : '—';
    sum.gender.textContent = dash(fields.gender.value);
    sum.blood.textContent = dash(fields.blood_type.value);
    sum.contact.textContent = dash(fields.contact_number.value);
    sum.email.textContent = dash(fields.email.value);
    var emailMirror = document.getElementById('reg_email_contact');
    if (emailMirror) emailMirror.value = fields.email.value.trim() || 'Set in Account Information below';
    sum.barangay.textContent = BARANGAY;
    sum.purok.textContent = dash(fields.purok.value);
    sum.regStatus.textContent = sectionReady() ? 'Ready to submit' : 'In progress';
    sum.accountStatus.textContent = 'Pending — created on register';

    setChecklistItem(checklist.personal, sectionPersonalDone());
    setChecklistItem(checklist.contact, sectionContactDone());
    setChecklistItem(checklist.medical, sectionMedicalDone());
    setChecklistItem(checklist.consent, sectionConsentDone());
    setChecklistItem(checklist.ready, sectionReady());

    if (pregnancyWrap) {
      var showPreg = (fields.gender.value || '').toLowerCase() === 'female';
      pregnancyWrap.hidden = !showPreg;
      if (!showPreg && fields.pregnancy) fields.pregnancy.value = '';
    }
  }

  function validateAll() {
    clearErrors();
    var inputs = [fields.first_name, fields.last_name, fields.date_of_birth, fields.gender,
      fields.contact_number, fields.email];
    var allOk = true;
    var firstBad = null;

    inputs.forEach(function (inp) {
      if (!validateField(inp)) {
        allOk = false;
        if (!firstBad) firstBad = inp;
      }
    });

    if (!fields.consent.checked || !fields.terms.checked) {
      allOk = false;
      var consentWrap = document.getElementById('reg_consent_wrap');
      if (consentWrap) consentWrap.classList.add('is-invalid');
      if (!firstBad) firstBad = fields.consent;
    } else {
      var cw = document.getElementById('reg_consent_wrap');
      if (cw) cw.classList.remove('is-invalid');
    }

    if (firstBad) {
      firstBad.focus({ preventScroll: true });
      firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    return allOk;
  }

  function buildSuccessMessage(r) {
    var lines = [];
    lines.push('Patient ID: ' + (r.patient_code || ('#' + r.patient_id)));
    lines.push('Email: ' + (r.email || '—'));
    lines.push('Account Status: ' + (r.account_status || 'Active'));
    if (r.email_sent) {
      lines.push('');
      lines.push('A password setup email has been sent to the patient.');
    } else if (r.temporary_password) {
      lines.push('');
      lines.push('⚠ This temporary password will only be shown once. Provide it securely to the patient:');
      lines.push(r.temporary_password);
    }
    return lines.join('\n');
  }

  function showRegistrationSuccess(r) {
    var loginUrl = (document.body.dataset.assetBase || '') + '/index.php?registered=1';
    var html = '<div class="bhw-reg-success-modal">' +
      '<p class="bhw-reg-success-lead"><strong>✔ Patient Registered Successfully</strong></p>' +
      '<dl class="bhw-reg-success-dl">' +
      '<div><dt>Patient ID</dt><dd>' + (r.patient_code || ('#' + r.patient_id)) + '</dd></div>' +
      '<div><dt>Email</dt><dd>' + (r.email || '—') + '</dd></div>' +
      '<div><dt>Account Status</dt><dd>Active</dd></div>' +
      '</dl>';
    if (r.email_sent) {
      html += '<p class="bhw-reg-success-note">A password setup email has been sent to the patient.</p>';
    } else if (r.temporary_password) {
      html += '<div class="bhw-reg-temp-pwd-warning">' +
        '<p><strong>Email delivery unavailable.</strong> This password will only be shown once. Please provide it securely to the patient.</p>' +
        '<code class="bhw-reg-temp-pwd" id="bhw_one_time_pwd">' + r.temporary_password + '</code>' +
        '</div>';
    }
    html += '</div>';

    BhwPortal.showFeedback({
      type: 'success',
      title: 'Patient Registered Successfully',
      message: html,
      html: true,
      primary: {
        label: 'Continue to Chief Complaint',
        href: r.redirect || ('../triage/submit.php?patient_id=' + (r.patient_id || '')),
      },
      secondary: { label: 'Register Another', action: function () {
        document.querySelector('.bhw-register-sidebar .bhw-summary-card').classList.remove('is-success');
        sum.id.textContent = 'Auto-generated on submit';
        sum.regStatus.textContent = 'In progress';
        sum.accountStatus.textContent = 'Pending — created on register';
      }},
      dismissible: false
    });
  }

  function loadPatientIndex() {
    return BhwPortal.get('patients.php', { action: 'list' }).then(function (r) {
      if (!r.success) return;
      patientIndex = { emails: {}, contacts: {} };
      (r.patients || []).forEach(function (p) {
        if (p.email) patientIndex.emails[String(p.email).toLowerCase()] = true;
        var c = normalizePhone(p.contact_number);
        if (c) patientIndex.contacts[c] = true;
      });
    });
  }

  form.querySelectorAll('input, select, textarea').forEach(function (el) {
    el.addEventListener('input', updateSummary);
    el.addEventListener('change', updateSummary);
    if (el.name && ['email', 'contact_number', 'first_name', 'last_name', 'date_of_birth', 'gender'].indexOf(el.name) >= 0) {
      el.addEventListener('blur', function () { validateField(el); updateSummary(); });
    }
  });

  fields.consent.addEventListener('change', updateSummary);
  fields.terms.addEventListener('change', updateSummary);

  document.getElementById('reg_reset_btn').addEventListener('click', function () {
    if (!confirm('Reset all fields? Unsaved data will be lost.')) return;
    form.reset();
    clearErrors();
    updateSummary();
  });

  document.getElementById('reg_cancel_btn').addEventListener('click', function (e) {
    if (!form.dataset.dirty) return;
    if (!confirm('Discard changes and leave this page?')) e.preventDefault();
  });

  form.addEventListener('input', function () { form.dataset.dirty = '1'; });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (submitting) return;
    if (!validateAll()) {
      BhwPortal.showFeedback({
        type: 'error',
        title: 'Check Required Fields',
        message: 'Please correct the highlighted fields before registering.'
      });
      return;
    }

    submitting = true;
    var fd = new FormData(form);
    fd.append('action', 'create');
    if (fields.consent.checked && fields.terms.checked) {
      fd.append('consent_given', '1');
    }
    submitBtn.disabled = true;
    submitBtn.textContent = 'Registering…';

    BhwPortal.post('patients.php', fd).then(function (r) {
      submitting = false;
      submitBtn.disabled = false;
      submitBtn.textContent = 'Register Patient';

      if (r.success) {
        form.reset();
        delete form.dataset.dirty;
        clearErrors();
        if (r.patient_code || r.patient_id) {
          sum.id.textContent = r.patient_code || ('Patient ID #' + r.patient_id);
          sum.regStatus.textContent = 'Registered';
          sum.accountStatus.textContent = 'Active';
          document.querySelector('.bhw-register-sidebar .bhw-summary-card').classList.add('is-success');
        }
        updateSummary();
        loadPatientIndex();
        showRegistrationSuccess(r);
      } else {
        BhwPortal.showFeedback({
          type: 'error',
          title: 'Registration Failed',
          message: r.message || 'Could not register patient. Please check the form and try again.'
        });
        if (r.message && r.message.toLowerCase().indexOf('email') >= 0) {
          setFieldError(fields.email, r.message);
          fields.email.focus();
        }
      }
    }).catch(function () {
      submitting = false;
      submitBtn.disabled = false;
      submitBtn.textContent = 'Register Patient';
      BhwPortal.showFeedback({
        type: 'error',
        title: 'Network Error',
        message: 'Could not reach the server. Check your connection and try again.'
      });
    });
  });

  loadPatientIndex().then(updateSummary);
  updateSummary();
})();
<?php
$bhw_inline_script = ob_get_clean();
?>
<div class="bhw-register-page">

  <header class="bhw-register-header">
    <div class="bhw-register-header-text">
      <h2 class="text-h2">Register Patient</h2>
      <p>Assisted registration for <strong>Brgy. <?= $barangay_label ?></strong>. Patient records sync automatically to your barangay GIS sector upon successful registration.</p>
    </div>
    <a href="list.php" class="bhw-btn-ghost bhw-register-back-link" aria-label="Back to patient list">← Back to Patient List</a>
  </header>

  <div class="bhw-register-grid">

    <div class="bhw-register-main">
      <form id="bhwRegisterForm" class="bhw-register-form" novalidate aria-label="Patient registration form">

        <!-- Card 1: Personal Information -->
        <section class="bhw-card bhw-form-card" aria-labelledby="reg_card_personal_title">
          <h3 class="bhw-form-card-title" id="reg_card_personal_title">
            <span class="bhw-card-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            Personal Information
          </h3>
          <p class="bhw-form-card-sub">Legal name and demographic details for the patient record.</p>
          <div class="bhw-form-grid">
            <div class="bhw-field">
              <label class="form-label" for="reg_first_name">First Name <span class="bhw-req" aria-hidden="true">*</span></label>
              <input type="text" class="form-control" id="reg_first_name" name="first_name" required autocomplete="given-name" placeholder="Juan" aria-required="true">
              <span class="bhw-field-error" role="alert"></span>
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_middle_name">Middle Name</label>
              <input type="text" class="form-control" id="reg_middle_name" name="middle_name" autocomplete="additional-name" placeholder="Santos" aria-describedby="reg_middle_hint">
              <span class="bhw-field-hint" id="reg_middle_hint">Optional — shown in summary only</span>
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_last_name">Last Name <span class="bhw-req" aria-hidden="true">*</span></label>
              <input type="text" class="form-control" id="reg_last_name" name="last_name" required autocomplete="family-name" placeholder="Dela Cruz" aria-required="true">
              <span class="bhw-field-error" role="alert"></span>
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_suffix">Suffix</label>
              <input type="text" class="form-control" id="reg_suffix" name="suffix" placeholder="Jr., Sr., III" aria-describedby="reg_suffix_hint">
              <span class="bhw-field-hint" id="reg_suffix_hint">Optional</span>
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_dob">Date of Birth <span class="bhw-req" aria-hidden="true">*</span></label>
              <input type="date" class="form-control" id="reg_dob" name="date_of_birth" required aria-required="true">
              <span class="bhw-field-error" role="alert"></span>
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_age">Age</label>
              <input type="text" class="form-control bhw-readonly" id="reg_age" readonly tabindex="-1" placeholder="Auto-computed" aria-live="polite">
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_gender">Gender <span class="bhw-req" aria-hidden="true">*</span></label>
              <select class="form-select" id="reg_gender" name="gender" required aria-required="true">
                <option value="">Select gender</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
              <span class="bhw-field-error" role="alert"></span>
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_civil_status">Civil Status</label>
              <select class="form-select" id="reg_civil_status" name="civil_status" aria-describedby="reg_civil_hint">
                <option value="">Select status</option>
                <option value="Single">Single</option>
                <option value="Married">Married</option>
                <option value="Widowed">Widowed</option>
                <option value="Separated">Separated</option>
                <option value="Annulled">Annulled</option>
              </select>
              <span class="bhw-field-hint" id="reg_civil_hint">Optional — for BHW reference</span>
            </div>
          </div>
        </section>

        <!-- Card 2: Contact Information -->
        <section class="bhw-card bhw-form-card" aria-labelledby="reg_card_contact_title">
          <h3 class="bhw-form-card-title" id="reg_card_contact_title">
            <span class="bhw-card-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </span>
            Contact Information
          </h3>
          <p class="bhw-form-card-sub">How the patient can be reached for appointments and follow-ups.</p>
          <div class="bhw-form-grid">
            <div class="bhw-field">
              <label class="form-label" for="reg_contact">Mobile Number <span class="bhw-req" aria-hidden="true">*</span></label>
              <input type="tel" class="form-control" id="reg_contact" name="contact_number" required autocomplete="tel" placeholder="09XXXXXXXXX" inputmode="numeric" aria-required="true">
              <span class="bhw-field-error" role="alert"></span>
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_email_contact">Email Address</label>
              <input type="email" class="form-control bhw-readonly" id="reg_email_contact" readonly tabindex="-1" value="Set in Account Information below" aria-describedby="reg_email_contact_hint">
              <span class="bhw-field-hint" id="reg_email_contact_hint">Mirrors account email</span>
            </div>
            <div class="bhw-field span-2">
              <label class="form-label" for="reg_address">Complete Address</label>
              <input type="text" class="form-control" id="reg_address" name="address" placeholder="House no., street, subdivision" autocomplete="street-address" aria-describedby="reg_address_hint">
              <span class="bhw-field-hint" id="reg_address_hint">Optional — for BHW reference; barangay is auto-assigned</span>
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_barangay">Barangay</label>
              <input type="text" class="form-control bhw-readonly" id="reg_barangay" readonly value="Brgy. <?= $barangay_label ?>" aria-readonly="true">
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_purok">Purok</label>
              <input type="text" class="form-control" id="reg_purok" name="purok" placeholder="e.g. Purok 3" aria-describedby="reg_purok_hint">
              <span class="bhw-field-hint" id="reg_purok_hint">Optional — for local reference</span>
            </div>
          </div>
        </section>

        <!-- Card 3: Medical Information -->
        <section class="bhw-card bhw-form-card" aria-labelledby="reg_card_medical_title">
          <h3 class="bhw-form-card-title" id="reg_card_medical_title">
            <span class="bhw-card-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            </span>
            Medical Information
          </h3>
          <p class="bhw-form-card-sub">Baseline clinical data to support triage and care coordination.</p>
          <div class="bhw-form-grid">
            <div class="bhw-field">
              <label class="form-label" for="reg_blood">Blood Type</label>
              <select class="form-select" id="reg_blood" name="blood_type">
                <option value="Unknown">Unknown</option>
                <option value="A+">A+</option>
                <option value="A-">A-</option>
                <option value="B+">B+</option>
                <option value="B-">B-</option>
                <option value="AB+">AB+</option>
                <option value="AB-">AB-</option>
                <option value="O+">O+</option>
                <option value="O-">O-</option>
              </select>
            </div>
            <div class="bhw-field" id="reg_pregnancy_wrap" hidden>
              <label class="form-label" for="reg_pregnancy">Pregnancy Status</label>
              <select class="form-select" id="reg_pregnancy" aria-describedby="reg_pregnancy_hint">
                <option value="">Not applicable / Unknown</option>
                <option value="Not pregnant">Not pregnant</option>
                <option value="Pregnant">Pregnant</option>
                <option value="Postpartum">Postpartum</option>
              </select>
              <span class="bhw-field-hint" id="reg_pregnancy_hint">Shown when gender is Female</span>
            </div>
            <div class="bhw-field span-2">
              <label class="form-label" for="reg_conditions">Existing Conditions</label>
              <textarea class="form-control" id="reg_conditions" name="existing_conditions" rows="3" placeholder="Hypertension, diabetes, asthma…" aria-describedby="reg_conditions_hint"></textarea>
              <span class="bhw-field-hint" id="reg_conditions_hint">Optional — for BHW reference</span>
            </div>
            <div class="bhw-field span-2">
              <label class="form-label" for="reg_allergies">Allergies</label>
              <textarea class="form-control" id="reg_allergies" name="allergies" rows="2" placeholder="Drug, food, or environmental allergies" aria-describedby="reg_allergies_hint"></textarea>
              <span class="bhw-field-hint" id="reg_allergies_hint">Optional — for BHW reference</span>
            </div>
            <div class="bhw-field span-2">
              <label class="form-label" for="reg_medications">Current Medications</label>
              <textarea class="form-control" id="reg_medications" name="medications" rows="2" placeholder="List ongoing prescriptions or supplements" aria-describedby="reg_meds_hint"></textarea>
              <span class="bhw-field-hint" id="reg_meds_hint">Optional — for BHW reference</span>
            </div>
            <div class="bhw-field span-2">
              <label class="form-label" for="reg_disabilities">Disabilities</label>
              <textarea class="form-control" id="reg_disabilities" rows="2" placeholder="Mobility, sensory, or other considerations" aria-describedby="reg_dis_hint"></textarea>
              <span class="bhw-field-hint" id="reg_dis_hint">Optional — for BHW reference</span>
            </div>
          </div>
        </section>

        <!-- Card 4: Emergency Contact -->
        <section class="bhw-card bhw-form-card" aria-labelledby="reg_card_emergency_title">
          <h3 class="bhw-form-card-title" id="reg_card_emergency_title">
            <span class="bhw-card-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </span>
            Emergency Contact
          </h3>
          <p class="bhw-form-card-sub">Person to contact in case of medical emergency.</p>
          <div class="bhw-form-grid">
            <div class="bhw-field span-2">
              <label class="form-label" for="reg_ec_name">Contact Name</label>
              <input type="text" class="form-control" id="reg_ec_name" name="emergency_contact_name" placeholder="Full name" autocomplete="name">
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_ec_phone">Contact Number</label>
              <input type="tel" class="form-control" id="reg_ec_phone" name="emergency_contact_phone" placeholder="09XXXXXXXXX" inputmode="numeric">
            </div>
            <div class="bhw-field">
              <label class="form-label" for="reg_ec_relation">Relationship</label>
              <input type="text" class="form-control" id="reg_ec_relation" name="emergency_contact_relation" placeholder="e.g. Spouse, Parent">
            </div>
          </div>
        </section>

        <!-- Card 5: Account Information -->
        <section class="bhw-card bhw-form-card" aria-labelledby="reg_card_account_title">
          <h3 class="bhw-form-card-title" id="reg_card_account_title">
            <span class="bhw-card-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            Account Information
          </h3>
          <p class="bhw-form-card-sub">The system will automatically create the patient account and send secure login instructions. You will not see or manage the patient's password.</p>
          <div class="bhw-form-grid">
            <div class="bhw-field span-2">
              <label class="form-label" for="reg_email">Email Address <span class="bhw-req" aria-hidden="true">*</span></label>
              <input type="email" class="form-control" id="reg_email" name="email" required autocomplete="email" placeholder="patient@email.com" aria-required="true">
              <span class="bhw-field-hint">Used for patient sign-in and password setup notifications.</span>
              <span class="bhw-field-error" role="alert"></span>
            </div>
          </div>
          <div class="bhw-account-auto-note" role="note">
            <strong>Automatic account creation:</strong> Patient ID, secure credentials, and Active status are assigned on submit. A password setup email is sent when available.
          </div>
        </section>

        <!-- Card 6: Consent & Confirmation -->
        <section class="bhw-card bhw-form-card bhw-consent-card" aria-labelledby="reg_card_consent_title">
          <h3 class="bhw-form-card-title" id="reg_card_consent_title">
            <span class="bhw-card-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </span>
            Consent &amp; Confirmation
          </h3>
          <p class="bhw-form-card-sub">Required acknowledgements before completing assisted registration.</p>
          <div class="bhw-consent-list" id="reg_consent_wrap">
            <label class="bhw-consent-item">
              <input type="checkbox" id="reg_consent" required aria-required="true">
              <span>I confirm that <strong>patient consent</strong> has been obtained under the <strong>Data Privacy Act (RA 10173)</strong> for collection and processing of health information.</span>
            </label>
            <label class="bhw-consent-item">
              <input type="checkbox" id="reg_terms" required aria-required="true">
              <span>The patient agrees to medConnect <strong>Terms of Service</strong> and <strong>Privacy Policy</strong>.</span>
            </label>
          </div>
          <p class="bhw-consent-note">By registering, GIS location will sync automatically to <strong>Brgy. <?= $barangay_label ?></strong>.</p>
        </section>

        <div class="bhw-form-actions bhw-register-actions">
          <a href="list.php" class="bhw-btn-ghost" id="reg_cancel_btn">Cancel</a>
          <button type="button" class="bhw-btn-outline" id="reg_reset_btn">Reset Form</button>
          <a href="list.php" class="bhw-btn-outline">Back to Patient List</a>
          <button type="submit" class="bhw-btn-teal" id="reg_submit_btn">Register Patient</button>
        </div>
      </form>
    </div>

    <aside class="bhw-register-sidebar" aria-label="Registration summary">
      <div class="bhw-card bhw-summary-card">
        <div class="bhw-summary-hero">
          <div class="bhw-summary-avatar" id="sum_avatar" aria-hidden="true">?</div>
          <h3 class="bhw-summary-name" id="sum_name">—</h3>
          <p class="bhw-summary-id" id="sum_id">Auto-generated on submit</p>
          <span class="bhw-summary-status" id="sum_reg_status_badge">New Registration</span>
        </div>
        <div class="bhw-summary-body">
          <div class="bhw-summary-section">
            <div class="bhw-summary-section-title">Patient Preview</div>
            <dl class="bhw-summary-dl">
              <div><dt>Age</dt><dd id="sum_age">—</dd></div>
              <div><dt>Gender</dt><dd id="sum_gender">—</dd></div>
              <div><dt>Blood Type</dt><dd id="sum_blood">—</dd></div>
              <div><dt>Contact</dt><dd id="sum_contact">—</dd></div>
              <div><dt>Email</dt><dd id="sum_email">—</dd></div>
              <div><dt>Barangay</dt><dd id="sum_barangay"><?= $barangay_label ?></dd></div>
              <div><dt>Purok</dt><dd id="sum_purok">—</dd></div>
              <div><dt>Registration</dt><dd id="sum_reg_status">In progress</dd></div>
              <div><dt>Account</dt><dd id="sum_account_status">Pending — created on register</dd></div>
            </dl>
          </div>

          <div class="bhw-summary-section bhw-checklist-section">
            <div class="bhw-summary-section-title">Registration Checklist</div>
            <ul class="bhw-checklist" role="list" aria-label="Registration progress">
              <li class="bhw-checklist-item is-pending" id="chk_personal">
                <span class="bhw-checklist-icon" aria-hidden="true">○</span>
                <span>Personal Information Completed</span>
              </li>
              <li class="bhw-checklist-item is-pending" id="chk_contact">
                <span class="bhw-checklist-icon" aria-hidden="true">○</span>
                <span>Contact Information Completed</span>
              </li>
              <li class="bhw-checklist-item is-pending" id="chk_medical">
                <span class="bhw-checklist-icon" aria-hidden="true">○</span>
                <span>Medical Information Completed</span>
              </li>
              <li class="bhw-checklist-item is-pending" id="chk_consent">
                <span class="bhw-checklist-icon" aria-hidden="true">○</span>
                <span>Consent Accepted</span>
              </li>
              <li class="bhw-checklist-item is-pending" id="chk_ready">
                <span class="bhw-checklist-icon" aria-hidden="true">○</span>
                <span>Ready to Register</span>
              </li>
            </ul>
          </div>

          <div class="bhw-register-guidance">
            <h4>Registration Guidance</h4>
            <ul>
              <li>Verify the patient's identity before submitting.</li>
              <li>Use a valid mobile number and email the patient actively uses.</li>
              <li>Credentials are sent directly to the patient — you cannot view their password.</li>
              <li>GIS sync assigns the patient to your barangay automatically.</li>
            </ul>
          </div>
        </div>
      </div>
    </aside>

  </div>

</div>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
