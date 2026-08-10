/**
 * Patient dashboard — chief complaint review with triage-gated supporting evidence.
 */
(function () {
  'use strict';

  var form = document.getElementById('pdashSymptomsReviewForm');
  if (!form) return;

  var alertEl = document.getElementById('pdashSymptomsReviewAlert');
  var submitBtn = document.getElementById('pdashSymptomsReviewSubmit');
  if (submitBtn && !submitBtn.dataset.defaultLabel) {
    submitBtn.dataset.defaultLabel = submitBtn.textContent.trim();
  }
  var finalSubmitLabel = submitBtn ? (submitBtn.dataset.defaultLabel || 'Submit chief complaint') : 'Submit chief complaint';

  var IMAGE_MAX = 5 * 1024 * 1024;
  var VIDEO_MAX = 25 * 1024 * 1024;
  var ALLOWED = {
    'image/jpeg': true,
    'image/png': true,
    'image/webp': true,
    'video/mp4': true,
    'video/webm': true,
  };

  var evidenceInput = document.getElementById('pdashSupportingEvidence');
  var chooseBtn = document.getElementById('pdashBtnChooseEvidence');
  var removeBtn = document.getElementById('pdashBtnRemoveEvidence');
  var filenameEl = document.getElementById('pdashEvidenceFilename');
  var previewEl = document.getElementById('pdashEvidencePreview');
  var complaintEl = document.getElementById('pdashSymptomsComplaint');
  var evidenceSection = document.getElementById('pdashCareEvidenceSection');
  var previewUrl = null;

  /** @type {null|'non_urgent'|'urgent'|'emergency'} */
  var triageLevel = null;
  var triageComplaint = '';

  function base() {
    return (typeof window.APP_BASE !== 'undefined' && window.APP_BASE)
      ? String(window.APP_BASE).replace(/\/$/, '')
      : '';
  }

  function csrf() {
    if (document.body && document.body.dataset && document.body.dataset.csrf) {
      return document.body.dataset.csrf;
    }
    var root = document.getElementById('medconnectThemeRoot');
    if (root && root.dataset && root.dataset.csrf) {
      return root.dataset.csrf;
    }
    return '';
  }

  function complaintText() {
    return String(complaintEl && complaintEl.value ? complaintEl.value : '').trim();
  }

  function hasValidComplaint() {
    return complaintText().length > 0;
  }

  function clearTriageState() {
    triageLevel = null;
    triageComplaint = '';
  }

  function canShowEvidence() {
    return (triageLevel === 'non_urgent' || triageLevel === 'urgent')
      && hasValidComplaint()
      && complaintText() === triageComplaint;
  }

  function isReadyForFinalSubmit() {
    return canShowEvidence();
  }

  function resolveTriageLevel(assessment) {
    if (!assessment || typeof assessment !== 'object') return null;

    var triage = assessment.triage && typeof assessment.triage === 'object' ? assessment.triage : {};
    var explicit = String(assessment.triage_level || '').toLowerCase();
    if (explicit === 'non_urgent' || explicit === 'urgent' || explicit === 'emergency') {
      return explicit;
    }

    var classification = String(triage.triage_classification || '').toUpperCase();
    if (classification === 'EMERGENCY') return 'emergency';
    if (classification === 'URGENT') return 'urgent';

    var dbLevel = String(triage.db_level || assessment.db_level || '').toLowerCase();
    if (dbLevel === '1' || dbLevel === 'high' || dbLevel === 'emergency') return 'emergency';
    if (dbLevel === '2' || dbLevel === 'urgent') return 'urgent';

    return 'non_urgent';
  }

  function showAlert(type, message) {
    if (!alertEl) return;
    alertEl.hidden = false;
    alertEl.className = 'patient-triage-alert patient-triage-alert--' + type + ' is-visible';
    alertEl.textContent = message;
  }

  function clearAlert() {
    if (!alertEl) return;
    alertEl.hidden = true;
    alertEl.className = 'patient-triage-alert';
    alertEl.textContent = '';
  }

  function clearPreview() {
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = null;
    }
    if (previewEl) {
      previewEl.innerHTML = '';
      previewEl.hidden = true;
    }
  }

  function resetEvidence() {
    if (evidenceInput) {
      evidenceInput.value = '';
    }
    clearPreview();
    if (filenameEl) {
      filenameEl.textContent = '';
      filenameEl.hidden = true;
    }
    if (removeBtn) {
      removeBtn.hidden = true;
      removeBtn.disabled = true;
    }
  }

  function syncEvidenceSection() {
    if (!evidenceSection) return;
    var show = canShowEvidence();

    evidenceSection.hidden = !show;
    evidenceSection.classList.toggle('pdash-care-evidence--collapsed', !show);
    evidenceSection.setAttribute('aria-hidden', show ? 'false' : 'true');

    if (show) {
      evidenceSection.removeAttribute('inert');
    } else {
      evidenceSection.setAttribute('inert', '');
    }

    if (evidenceInput) {
      evidenceInput.disabled = !show;
      if (show) {
        evidenceInput.removeAttribute('tabindex');
      } else {
        evidenceInput.setAttribute('tabindex', '-1');
      }
    }

    if (chooseBtn) {
      chooseBtn.disabled = !show;
    }

    if (removeBtn && !show) {
      removeBtn.disabled = true;
    }

    if (!show) {
      resetEvidence();
    }
  }

  function updateSubmitButtonLabel() {
    if (!submitBtn) return;
    if (isReadyForFinalSubmit()) {
      submitBtn.textContent = finalSubmitLabel;
      return;
    }
    submitBtn.textContent = finalSubmitLabel;
  }

  function onComplaintChanged() {
    if (complaintText() !== triageComplaint) {
      clearTriageState();
      resetEvidence();
    }
    syncEvidenceSection();
    updateSubmitButtonLabel();
  }

  function validateEvidenceFile(file) {
    if (!file) return null;
    if (!ALLOWED[file.type]) {
      return 'Please choose a JPG, PNG, WEBP photo or MP4/WebM video.';
    }
    var isVideo = file.type.indexOf('video/') === 0;
    var maxSize = isVideo ? VIDEO_MAX : IMAGE_MAX;
    if (file.size > maxSize) {
      return isVideo ? 'Video must be 25 MB or smaller.' : 'Photo must be 5 MB or smaller.';
    }
    return null;
  }

  function canUseEvidenceUpload() {
    return canShowEvidence() && evidenceInput && !evidenceInput.disabled;
  }

  async function runTriageAssessment(complaint) {
    var fd = new FormData();
    fd.set('chief_complaint', complaint);
    fd.set('csrf_token', csrf());

    var res = await fetch(base() + '/app/api/patient/assess_symptoms.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-MC-No-Loader': '1' },
    });

    var data = await res.json().catch(function () { return null; });
    if (!data || !data.success || !data.assessment) {
      showAlert('error', (data && data.message) || 'Could not analyze your symptoms. Please try again.');
      return null;
    }

    return data.assessment;
  }

  async function submitForReview(complaint, evidenceFile) {
    if (evidenceFile) {
      var evidenceErr = validateEvidenceFile(evidenceFile);
      if (evidenceErr) {
        showAlert('error', evidenceErr);
        return;
      }
    }

    var fd = new FormData(form);
    fd.set('chief_complaint', complaint);
    fd.set('csrf_token', csrf());
    if (!evidenceFile) {
      fd.delete('supporting_evidence');
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting…';
    }

    try {
      var res = await fetch(base() + '/app/api/patient/submit_symptoms_review.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
      });
      var data = await res.json().catch(function () { return null; });
      if (!data || !data.success) {
        showAlert('error', (data && data.message) || 'Could not submit. Please try again.');
        return;
      }

      var payload = data.data || data;
      if (payload.emergency) {
        clearTriageState();
        syncEvidenceSection();
        var emMsg = data.message || 'Emergency symptoms detected. Seek emergency care.';
        showAlert('error', emMsg);
        if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showEmergency === 'function') {
          window.mcPatientUrgencyModal.showEmergency(emMsg);
        }
        return;
      }
      if (payload.urgent) {
        var urgMsg = data.message || 'Please book an urgent consultation.';
        var urgentComplaint = complaint;
        var triageId = payload.triage_id ? payload.triage_id : 0;
        showAlert('error', urgMsg);
        if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showUrgent === 'function') {
          window.mcPatientUrgencyModal.showUrgent(urgMsg, payload.book_url || '', {
            complaint: urgentComplaint,
            triageId: triageId,
          });
        } else if (payload.book_url) {
          setTimeout(function () { window.location.href = payload.book_url; }, 2200);
        }
        return;
      }

      showAlert('success', data.message || 'Submitted for provider review.');
      setTimeout(function () { window.location.reload(); }, 1400);
    } catch (_) {
      showAlert('error', 'Network error. Please try again.');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        updateSubmitButtonLabel();
      }
    }
  }

  async function processTriageCheck(complaint) {
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Checking symptoms…';
    }

    try {
      var assessment = await runTriageAssessment(complaint);
      if (!assessment) return false;

      var level = resolveTriageLevel(assessment);
      if (!level) {
        showAlert('error', 'Could not determine triage level. Please try again.');
        return false;
      }

      triageComplaint = complaint;
      triageLevel = level;

      if (level === 'emergency') {
        syncEvidenceSection();
        await submitForReview(complaint, null);
        return true;
      }

      syncEvidenceSection();
      updateSubmitButtonLabel();

      if (level === 'urgent') {
        showAlert('success', 'Urgent symptoms detected. You may add optional supporting evidence, then submit to continue.');
      } else {
        showAlert('success', 'Symptoms checked. You may add optional supporting evidence, then submit for review.');
      }
      return true;
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        updateSubmitButtonLabel();
      }
    }
  }

  if (chooseBtn && evidenceInput) {
    chooseBtn.addEventListener('click', function (e) {
      if (!canUseEvidenceUpload()) {
        e.preventDefault();
        e.stopPropagation();
        resetEvidence();
        return;
      }
      evidenceInput.click();
    });
  }

  if (removeBtn) {
    removeBtn.addEventListener('click', function () {
      if (!canUseEvidenceUpload()) {
        resetEvidence();
        return;
      }
      resetEvidence();
    });
  }

  if (evidenceInput) {
    evidenceInput.addEventListener('change', function () {
      if (!canUseEvidenceUpload()) {
        resetEvidence();
        return;
      }

      clearPreview();
      var file = evidenceInput.files && evidenceInput.files[0];
      if (!file) {
        resetEvidence();
        return;
      }

      var err = validateEvidenceFile(file);
      if (err) {
        resetEvidence();
        showAlert('error', err);
        return;
      }

      if (filenameEl) {
        filenameEl.textContent = file.name;
        filenameEl.hidden = false;
      }
      if (removeBtn) {
        removeBtn.hidden = false;
        removeBtn.disabled = false;
      }

      if (previewEl) {
        previewUrl = URL.createObjectURL(file);
        if (file.type.indexOf('video/') === 0) {
          previewEl.innerHTML = '<video src="' + previewUrl + '" controls playsinline muted></video>';
        } else {
          previewEl.innerHTML = '<img src="' + previewUrl + '" alt="Supporting evidence preview">';
        }
        previewEl.hidden = false;
      }
    });

    evidenceInput.addEventListener('click', function (e) {
      if (!canUseEvidenceUpload()) {
        e.preventDefault();
        e.stopPropagation();
        resetEvidence();
      }
    });
  }

  if (complaintEl) {
    complaintEl.addEventListener('input', onComplaintChanged);
    complaintEl.addEventListener('change', onComplaintChanged);
    complaintEl.addEventListener('paste', function () {
      window.setTimeout(onComplaintChanged, 0);
    });
  }

  syncEvidenceSection();
  updateSubmitButtonLabel();

  if (complaintEl && complaintEl.hasAttribute('readonly') && hasValidComplaint()) {
    processTriageCheck(complaintText());
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearAlert();

    var complaint = complaintText();
    if (!complaint) {
      clearTriageState();
      syncEvidenceSection();
      var locked = complaintEl && complaintEl.hasAttribute('readonly');
      showAlert('error', locked
        ? 'Your chief complaint from registration is missing. Please contact the health office.'
        : 'Please describe your symptoms or concern.');
      return;
    }

    if (!isReadyForFinalSubmit()) {
      await processTriageCheck(complaint);
      return;
    }

    var evidenceFile = null;
    if (canUseEvidenceUpload() && evidenceInput && evidenceInput.files) {
      evidenceFile = evidenceInput.files[0] || null;
    }

    await submitForReview(complaint, evidenceFile);
  });
})();
