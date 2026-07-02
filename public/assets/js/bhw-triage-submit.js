/**
 * BHW Triage & Book Consultation form logic.
 */
(function () {
  'use strict';

  var form = document.getElementById('bhwTriageForm');
  if (!form || typeof window.BhwPortal === 'undefined') return;

  var config = window.bhwTriageConfig || {};
  var pre = parseInt(config.preselect, 10) || 0;
  var showPreview = !!config.showPreview;
  var isEmergency = false;

  var complaintEl = document.getElementById('bhwComplaint');
  var symptomsEl = document.getElementById('bhwSymptoms');
  var out = document.getElementById('bhwAssessmentOut');
  var asidePlaceholder = document.getElementById('bhwAssessmentPlaceholder');
  var emergencyBanner = document.getElementById('bhwEmergencyBanner');
  var slotWrap = document.getElementById('bhwSlotWrap');
  var consentWrap = document.getElementById('bhwConsentWrap');
  var submitBtn = document.getElementById('bhwSubmitBtn');
  var slotEl = document.getElementById('bhwSlot');
  var providerEl = document.getElementById('bhwProvider');
  var dateEl = document.getElementById('bhwApptDate');
  var patientEl = document.getElementById('bhwPatient');
  var previewBtn = document.getElementById('bhwPreviewBtn');

  function setEmergencyMode(on) {
    isEmergency = on;
    if (emergencyBanner) emergencyBanner.style.display = on ? 'block' : 'none';
    if (slotWrap) slotWrap.style.display = on ? 'none' : '';
    if (consentWrap) consentWrap.style.display = on ? 'none' : '';
    if (out) out.classList.toggle('is-emergency', on);

    var consent = document.getElementById('bhwTeleConsent');
    if (!submitBtn || !slotEl) return;

    if (on) {
      slotEl.removeAttribute('required');
      if (consent) consent.checked = false;
      submitBtn.textContent = 'Submit Emergency Referral';
      submitBtn.classList.remove('bhw-btn-teal');
      submitBtn.classList.add('bhw-btn-outline');
      submitBtn.style.background = '#dc3545';
      submitBtn.style.color = '#fff';
      submitBtn.style.borderColor = '#dc3545';
    } else {
      slotEl.setAttribute('required', 'required');
      submitBtn.textContent = 'Submit Triage & Book';
      submitBtn.classList.add('bhw-btn-teal');
      submitBtn.classList.remove('bhw-btn-outline');
      submitBtn.style.background = '';
      submitBtn.style.color = '';
      submitBtn.style.borderColor = '';
    }
  }

  function showAssessment(text, emergency) {
    if (asidePlaceholder) asidePlaceholder.style.display = 'none';
    if (out) {
      out.style.display = 'block';
      out.textContent = text;
      out.classList.toggle('is-emergency', !!emergency);
    }
  }

  function loadSlots() {
    if (!providerEl || !dateEl || !slotEl) return;
    if (!providerEl.value || !dateEl.value) return;

    slotEl.innerHTML = '<option value="">Loading slots…</option>';
    BhwPortal.get('appointments.php', {
      action: 'slots',
      provider_id: providerEl.value,
      date: dateEl.value,
    }).then(function (r) {
      slotEl.innerHTML = '';
      if (!(r.slots || []).length) {
        slotEl.innerHTML = '<option value="">No slots available for this date</option>';
        return;
      }
      (r.slots || []).forEach(function (s) {
        var o = document.createElement('option');
        o.value = s.id;
        o.textContent = s.label;
        slotEl.appendChild(o);
      });
    }).catch(function () {
      slotEl.innerHTML = '<option value="">Could not load slots</option>';
    });
  }

  function parseSymptoms() {
    return (symptomsEl.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  }

  function applyAssessment(r) {
    if (r.success && r.assessment) {
      var a = r.assessment;
      var text = [
        'Urgency: ' + (a.urgency_label || '—'),
        'Level: ' + (a.db_level || '—'),
        'Severity: ' + ((a.severity && a.severity.severity) || '—'),
        '',
        'Recommendations:',
        ((a.recommendations || []).join('\n') || '—'),
      ].join('\n');
      showAssessment(text, !!r.is_emergency);
      setEmergencyMode(!!r.is_emergency);
    } else {
      showAssessment(r.message || 'Assessment unavailable.', false);
      setEmergencyMode(false);
    }
  }

  BhwPortal.loadPatients(patientEl).then(function () {
    if (pre && patientEl) patientEl.value = String(pre);
  });

  BhwPortal.get('appointments.php', { action: 'providers' }).then(function (r) {
    if (!providerEl) return;
    (r.providers || []).forEach(function (p) {
      var o = document.createElement('option');
      o.value = p.id;
      o.textContent = 'Dr. ' + p.last_name + ', ' + p.first_name;
      providerEl.appendChild(o);
    });
    if (providerEl.options.length > 1) {
      providerEl.selectedIndex = 1;
      loadSlots();
    }
  });

  if (providerEl) providerEl.addEventListener('change', loadSlots);
  if (dateEl) dateEl.addEventListener('change', loadSlots);

  if (previewBtn) {
    previewBtn.addEventListener('click', function () {
      BhwPortal.post('triage.php', {
        action: 'assess',
        chief_complaint: complaintEl.value,
        symptoms: parseSymptoms(),
      }).then(applyAssessment);
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    BhwPortal.post('triage.php', {
      action: 'assess',
      chief_complaint: complaintEl.value,
      symptoms: parseSymptoms(),
    }).then(function (assessRes) {
      applyAssessment(assessRes);
      var emergency = !!assessRes.is_emergency;
      var consent = document.getElementById('bhwTeleConsent');
      if (!emergency && consent && !consent.checked) {
        BhwPortal.toast('Teleconsult consent is required before booking.', false);
        return;
      }

      var fd = new FormData(form);
      var syms = parseSymptoms();
      fd.delete('symptoms');
      syms.forEach(function (s) { fd.append('symptoms[]', s); });
      fd.append('action', 'submit');
      if (emergency) {
        fd.delete('slot_id');
        fd.append('slot_id', '0');
        fd.delete('teleconsult_consent');
      }

      return BhwPortal.post('triage.php', fd).then(function (r) {
        if (r.emergency) {
          BhwPortal.showFeedback({
            type: 'success',
            title: 'Emergency Referral Created',
            message: r.message || 'Patient referred to hospital. Teleconsult was not booked.',
            primary: { label: 'View Referrals', href: r.redirect || '../referral/status.php' },
          });
          setTimeout(function () {
            location.href = r.redirect || '../referral/status.php';
          }, 2500);
          return;
        }
        BhwPortal.toast(r.message, r.success);
        if (r.success) {
          location.href = '../consultations/index.php?filter=active';
        }
      });
    });
  });

  if (showPreview && previewBtn) previewBtn.click();
})();
