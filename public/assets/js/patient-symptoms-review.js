/**
 * Patient dashboard — chief complaint review.
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
  var finalSubmitLabel = submitBtn ? (submitBtn.dataset.defaultLabel || 'Submit patient complaint') : 'Submit patient complaint';

  /** Visible copy when i18n module has not loaded yet — never expose internal keys. */
  var I18N_FALLBACKS = {
    submit_complaint: 'Submit patient complaint',
    submit_review: 'Submit for doctor review',
    submitting: 'Submitting…',
    assessing: 'Assessing urgency…',
    err_analyze: 'Could not analyze your complaint. Please try again.',
    err_timeout: 'Analysis timed out. Please try again.',
    err_network: 'Network error. Please try again.',
    err_submit: 'Could not submit. Please try again.',
    err_triage_level: 'Could not determine triage level. Please try again.',
    err_min_chars: 'Please provide a bit more detail (at least 10 characters).',
    err_empty: 'Please describe your symptoms or concern.',
    err_locked: 'Your patient complaint is not available. Please contact the health office.',
    ok_submitted: 'Submitted for provider review.',
    em_submit: 'Emergency symptoms detected. Seek emergency care.',
    urg_submit: 'Please book an urgent consultation.',
    msg_emergency: 'Based on the symptoms you entered, your condition may be a medical emergency. Please seek immediate medical attention at the nearest hospital or emergency department. Do not wait for an online consultation if you are experiencing severe or worsening symptoms.',
    msg_urgent: 'Based on the symptoms you provided, your condition may require prompt medical attention. Triage result: URGENT. After you submit, you can book the earliest available consultation time.',
    msg_non_urgent: 'Triage result: NON-URGENT. Submit for provider review.',
  };

  function i18n(key) {
    if (window.McPatientTriageI18n && typeof window.McPatientTriageI18n.t === 'function') {
      return window.McPatientTriageI18n.t(key);
    }
    return I18N_FALLBACKS[key] || key;
  }

  function resolveSubmitLabel() {
    if (!submitBtn) return 'Submit patient complaint';
    var review = submitBtn.getAttribute('data-submit-kind') === 'review';
    return i18n(review ? 'submit_review' : 'submit_complaint');
  }

  function refreshSubmitLabels() {
    if (!submitBtn) return;
    finalSubmitLabel = resolveSubmitLabel();
    submitBtn.dataset.defaultLabel = finalSubmitLabel;
    if (!submitBtn.disabled) submitBtn.textContent = finalSubmitLabel;
  }

  refreshSubmitLabels();

  var complaintEl = document.getElementById('pdashSymptomsComplaint');

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

  function isReadyForFinalSubmit() {
    return (triageLevel === 'non_urgent' || triageLevel === 'urgent')
      && hasValidComplaint()
      && complaintText() === triageComplaint;
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
    if (level === 'emergency') return i18n('msg_emergency');
    if (level === 'urgent') return i18n('msg_urgent');
    return i18n('msg_non_urgent');
  }

  function presentTriageOutcome(level, complaint, apiPayload) {
    if (window.McPatientTriageI18n && typeof window.McPatientTriageI18n.resolveForComplaint === 'function') {
      window.McPatientTriageI18n.resolveForComplaint(complaint || '', apiPayload || {});
    }
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

  function updateSubmitButtonLabel() {
    if (!submitBtn) return;
    finalSubmitLabel = resolveSubmitLabel();
    submitBtn.dataset.defaultLabel = finalSubmitLabel;
    submitBtn.textContent = finalSubmitLabel;
  }

  function onComplaintChanged() {
    if (complaintText() !== triageComplaint) {
      clearTriageState();
    }
    updateSubmitButtonLabel();
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
        showAlert('error', i18n('err_analyze'));
        return null;
      }

      var data = (json && (json.data || json)) || {};
      if (!res.ok || json.success === false) {
        if (data.summary || data.assessment || data.clinical_urgency) {
          return data;
        }
        showAlert('error', json.message || i18n('err_analyze'));
        return null;
      }

      return data;
    } catch (err) {
      window.clearTimeout(timer);
      if (err && err.name === 'AbortError') {
        showAlert('error', i18n('err_timeout'));
      } else {
        showAlert('error', i18n('err_network'));
      }
      return null;
    }
  }

  async function submitForReview(complaint, options) {
    options = options || {};

    var fd = new FormData(form);
    fd.set('chief_complaint', complaint);
    fd.set('csrf_token', csrf());

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = i18n('submitting');
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
        showAlert('error', (data && data.message) || i18n('err_submit'));
        return false;
      }

      var payload = data.data || data;
      if (payload.emergency) {
        clearTriageState();
        var emMsg = i18n('em_submit');
        if (!options.skipOutcomeModal) {
          showAlert('error', emMsg);
          if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showEmergency === 'function') {
            window.mcPatientUrgencyModal.showEmergency(emMsg);
          }
        }
        return true;
      }
      if (payload.urgent) {
        var urgMsg = i18n('urg_submit');
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

      showAlert('success', data.message || i18n('ok_submitted'));
      if (window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
      var waitingForSlot = !!(payload.waiting_for_slot);
      setTimeout(function () {
        if (waitingForSlot) {
          window.location.href = base() + '/views/patient/dashboard.php';
        } else {
          window.location.reload();
        }
      }, 1400);
      return true;
    } catch (_) {
      showAlert('error', i18n('err_network'));
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
      showAlert('error', i18n('err_min_chars'));
      return false;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = i18n('assessing');
    }

    try {
      var step3Data = await runStep3Triage(complaint);
      if (!step3Data) return false;

      var urgency = extractUrgency(step3Data);
      var level = urgencyToLevel(urgency);
      if (!level) {
        showAlert('error', i18n('err_triage_level'));
        return false;
      }

      triageComplaint = complaint;
      triageLevel = level;

      if (level === 'emergency') {
        var emergencySubmitted = await submitForReview(complaint, { skipOutcomeModal: true });
        if (emergencySubmitted) {
          presentTriageOutcome(level, complaint, step3Data);
        } else {
          clearTriageState();
        }
        return emergencySubmitted;
      }

      presentTriageOutcome(level, complaint, step3Data);
      updateSubmitButtonLabel();
      return true;
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        updateSubmitButtonLabel();
      }
    }
  }

  if (complaintEl) {
    complaintEl.addEventListener('input', onComplaintChanged);
    complaintEl.addEventListener('change', onComplaintChanged);
    complaintEl.addEventListener('paste', function () {
      window.setTimeout(onComplaintChanged, 0);
    });
  }

  updateSubmitButtonLabel();

  window.addEventListener('medconnect:patient-ui-lang', function () {
    refreshSubmitLabels();
    updateSubmitButtonLabel();
  });

  // Deferred i18n loads after this script; re-sync label once translations are available.
  window.addEventListener('load', function () {
    refreshSubmitLabels();
    if (submitBtn && !submitBtn.disabled) updateSubmitButtonLabel();
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearAlert();

    var complaint = complaintText();
    if (!complaint) {
      clearTriageState();
      var locked = complaintEl && complaintEl.hasAttribute('readonly');
      showAlert('error', locked
        ? i18n('err_locked')
        : i18n('err_empty'));
      return;
    }

    if (!isReadyForFinalSubmit()) {
      await processTriageCheck(complaint);
      return;
    }

    await submitForReview(complaint);
  });
})();
