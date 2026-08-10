/**
 * Patient dashboard — chief complaint review with triage-gated supporting evidence.
 * Triage uses the same Step 3 pipeline as registration (assess_chief_complaint.php).
 */
(function () {
  'use strict';

  var MIN_CHARS = 10;
  var ANALYZE_TIMEOUT_MS = 120000;

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

  function shouldRunTriage(text) {
    return String(text || '').trim().length >= MIN_CHARS;
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

  /** Same urgency extraction as register-nlp-analysis.js (Registration Step 3). */
  function extractUrgency(data) {
    if (!data || typeof data !== 'object') return '';
    var reg = data.registration || {};
    if (reg.urgency) return String(reg.urgency).toUpperCase().replace(/_/g, '-');
    var summary = data.summary || {};
    var clinical = data.clinical_urgency || reg.clinical_urgency || {};
    var rawSource = summary.classification || clinical.triage_display || clinical.urgency || clinical.classification || '';
    if (!rawSource && data.assessment && data.assessment.triage) {
      var triage = data.assessment.triage;
      rawSource = triage.triage_display || triage.triage_classification || '';
    }
    if (!rawSource) return '';

    var raw = String(rawSource)
      .trim()
      .toUpperCase()
      .replace(/\s+/g, '-')
      .replace(/_/g, '-');

    if (raw.indexOf('EMERGENCY') !== -1) return 'EMERGENCY';
    if (raw.indexOf('URGENT') !== -1 && raw.indexOf('NON') === -1) return 'URGENT';
    return 'NON-URGENT';
  }

  function urgencyToLevel(urgency) {
    var raw = String(urgency || '').trim().toUpperCase().replace(/_/g, '-');
    if (raw === 'EMERGENCY' || raw.indexOf('EMERGENCY') !== -1) return 'emergency';
    if (raw === 'URGENT' || (raw.indexOf('URGENT') !== -1 && raw.indexOf('NON') === -1)) return 'urgent';
    if (raw === 'NON-URGENT' || raw.indexOf('NON-URGENT') !== -1) return 'non_urgent';
    return null;
  }

  function buildTriageOutcomeMessage(level) {
    if (level === 'emergency') {
      return 'Based on the symptoms you entered, your condition may be a medical emergency. '
        + 'Please seek immediate medical attention at the nearest hospital or emergency department. '
        + 'Do not wait for an online consultation if you are experiencing severe or worsening symptoms.';
    }
    if (level === 'urgent') {
      return 'Based on the symptoms you provided, your condition may require prompt medical attention. '
        + 'Triage result: Urgent. After you submit, you can book the earliest available consultation time.';
    }
    return 'Triage result: Non-Urgent. You may add optional supporting evidence, then submit for provider review.';
  }

  function presentTriageOutcome(level) {
    var modal = window.mcPatientUrgencyModal;
    if (!modal || typeof modal.showTriageResult !== 'function') return;
    modal.showTriageResult(level, buildTriageOutcomeMessage(level));
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

  async function runStep3Triage(complaint) {
    var body = new FormData();
    body.append('chief_complaint', complaint);

    var controller = new AbortController();
    var timer = window.setTimeout(function () {
      controller.abort();
    }, ANALYZE_TIMEOUT_MS);

    try {
      var res = await fetch(base() + '/app/api/ai/assess_chief_complaint.php', {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        signal: controller.signal,
        headers: { 'X-MC-No-Loader': '1' },
      });
      window.clearTimeout(timer);

      var json = await res.json().catch(function () { return null; });
      if (!json) {
        showAlert('error', 'Could not analyze your complaint. Please try again.');
        return null;
      }

      var data = (json && (json.data || json)) || {};
      if (!res.ok || json.success === false) {
        if (data.summary || data.assessment || data.clinical_urgency) {
          return data;
        }
        showAlert('error', json.message || 'Could not analyze your complaint. Please try again.');
        return null;
      }

      return data;
    } catch (err) {
      window.clearTimeout(timer);
      if (err && err.name === 'AbortError') {
        showAlert('error', 'Analysis timed out. Please try again.');
      } else {
        showAlert('error', 'Network error. Please try again.');
      }
      return null;
    }
  }

  async function submitForReview(complaint, evidenceFile, options) {
    options = options || {};
    if (evidenceFile) {
      var evidenceErr = validateEvidenceFile(evidenceFile);
      if (evidenceErr) {
        showAlert('error', evidenceErr);
        return false;
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
        return false;
      }

      var payload = data.data || data;
      if (payload.emergency) {
        clearTriageState();
        syncEvidenceSection();
        var emMsg = data.message || 'Emergency symptoms detected. Seek emergency care.';
        if (!options.skipOutcomeModal) {
          showAlert('error', emMsg);
          if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showEmergency === 'function') {
            window.mcPatientUrgencyModal.showEmergency(emMsg);
          }
        }
        return true;
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
        return true;
      }

      showAlert('success', data.message || 'Submitted for provider review.');
      setTimeout(function () { window.location.reload(); }, 1400);
      return true;
    } catch (_) {
      showAlert('error', 'Network error. Please try again.');
      return false;
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        updateSubmitButtonLabel();
      }
    }
  }

  async function processTriageCheck(complaint) {
    if (!shouldRunTriage(complaint)) {
      showAlert('error', 'Please provide a bit more detail (at least 10 characters).');
      return false;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Assessing urgency…';
    }

    try {
      var step3Data = await runStep3Triage(complaint);
      if (!step3Data) return false;

      var urgency = extractUrgency(step3Data);
      var level = urgencyToLevel(urgency);
      if (!level) {
        showAlert('error', 'Could not determine triage level. Please try again.');
        return false;
      }

      triageComplaint = complaint;
      triageLevel = level;

      if (level === 'emergency') {
        syncEvidenceSection();
        var emergencySubmitted = await submitForReview(complaint, null, { skipOutcomeModal: true });
        if (emergencySubmitted) {
          presentTriageOutcome(level);
        } else {
          clearTriageState();
          syncEvidenceSection();
        }
        return emergencySubmitted;
      }

      presentTriageOutcome(level);
      syncEvidenceSection();
      updateSubmitButtonLabel();
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

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearAlert();

    var complaint = complaintText();
    if (!complaint) {
      clearTriageState();
      syncEvidenceSection();
      var locked = complaintEl && complaintEl.hasAttribute('readonly');
      showAlert('error', locked
        ? 'Your chief complaint is not available. Please contact the health office.'
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
