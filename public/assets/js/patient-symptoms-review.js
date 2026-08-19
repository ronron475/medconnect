/**
 * Patient dashboard — chief complaint review.
 * Two-step submit: (1) AI preliminary triage, (2) provider/slot assignment.
 */
(function () {
  'use strict';

  var MIN_CHARS = 10;
  var ANALYZE_TIMEOUT_MS = 120000;
  var SUBMIT_LABEL = 'Submit patient complaint';
  var CONTINUE_MSG = 'Please click "Submit patient complaint" again to continue.';

  var form = document.getElementById('pdashSymptomsReviewForm');
  if (!form) return;

  var alertEl = document.getElementById('pdashSymptomsReviewAlert');
  var submitBtn = document.getElementById('pdashSymptomsReviewSubmit');
  var aiResultEl = document.getElementById('pdashSymptomsAiResult');
  var aiLevelEl = document.getElementById('pdashSymptomsAiLevel');
  var continueHintEl = document.getElementById('pdashSymptomsContinueHint');
  if (submitBtn && !submitBtn.dataset.defaultLabel) {
    submitBtn.dataset.defaultLabel = SUBMIT_LABEL;
  }

  /** Visible copy when i18n module has not loaded yet — never expose internal keys. */
  var I18N_FALLBACKS = {
    submit_complaint: SUBMIT_LABEL,
    submit_review: SUBMIT_LABEL,
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
    msg_non_urgent: 'Preliminary AI Assessment: NON-URGENT. Please click "Submit patient complaint" again to continue.',
    click_again_continue: CONTINUE_MSG,
    ai_preliminary: 'Preliminary AI Assessment: {level}',
  };

  function i18n(key, vars) {
    var text = '';
    if (window.McPatientTriageI18n && typeof window.McPatientTriageI18n.t === 'function') {
      text = window.McPatientTriageI18n.t(key, vars);
    }
    if (!text || text === key) {
      text = I18N_FALLBACKS[key] || key;
      if (vars && typeof vars === 'object') {
        Object.keys(vars).forEach(function (name) {
          text = text.replace(new RegExp('\\{' + name + '\\}', 'g'), String(vars[name] == null ? '' : vars[name]));
        });
      }
    }
    return text;
  }

  function resolveSubmitLabel() {
    return SUBMIT_LABEL;
  }

  function refreshSubmitLabels() {
    if (!submitBtn) return;
    submitBtn.dataset.defaultLabel = SUBMIT_LABEL;
    if (!submitBtn.disabled) submitBtn.textContent = SUBMIT_LABEL;
  }

  refreshSubmitLabels();

  var complaintEl = document.getElementById('pdashSymptomsComplaint');

  /** @type {null|'non_urgent'|'urgent'|'emergency'} */
  var triageLevel = null;
  var triageComplaint = '';
  var triageId = 0;
  var awaitingSecondClick = false;
  var submitInFlight = false;

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

  function hideContinueUi() {
    if (aiResultEl) {
      aiResultEl.hidden = true;
      aiResultEl.classList.remove('is-visible');
    }
  }

  function classificationLabel(level, fallback) {
    var raw = String(fallback || '').trim().toUpperCase().replace(/_/g, '-');
    if (level === 'emergency' || raw.indexOf('EMERGENCY') !== -1) return 'EMERGENCY';
    if (level === 'urgent' || (raw.indexOf('URGENT') !== -1 && raw.indexOf('NON') === -1)) return 'URGENT';
    return 'NON-URGENT';
  }

  function showContinueUi(level, label) {
    var shown = classificationLabel(level, label);
    if (aiLevelEl) aiLevelEl.textContent = shown;
    if (continueHintEl) continueHintEl.textContent = CONTINUE_MSG;
    if (aiResultEl) {
      aiResultEl.hidden = false;
      aiResultEl.classList.add('is-visible');
    }
    showAlert(
      'warning',
      i18n('ai_preliminary', { level: shown }) + '\n\n' + CONTINUE_MSG
    );
  }

  function clearTriageState() {
    triageLevel = null;
    triageComplaint = '';
    triageId = 0;
    awaitingSecondClick = false;
    hideContinueUi();
  }

  function isReadyForFinalSubmit() {
    return awaitingSecondClick
      && triageId > 0
      && (triageLevel === 'non_urgent' || triageLevel === 'urgent')
      && hasValidComplaint()
      && complaintText() === triageComplaint;
  }

  /** Same urgency extraction as register-nlp-analysis.js (Registration Step 3). */
  function extractUrgency(data) {
    if (!data || typeof data !== 'object') return '';
    if (data.classification_label) {
      return String(data.classification_label).toUpperCase().replace(/_/g, '-');
    }
    if (data.triage_level) {
      var mapped = String(data.triage_level).toUpperCase().replace(/_/g, '-');
      if (mapped === 'NON-URGENT' || mapped === 'NONURGENT') return 'NON-URGENT';
      return mapped;
    }
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
    submitBtn.dataset.defaultLabel = SUBMIT_LABEL;
    submitBtn.textContent = SUBMIT_LABEL;
  }

  function onComplaintChanged() {
    if (complaintText() !== triageComplaint) {
      clearTriageState();
    }
    updateSubmitButtonLabel();
  }

  function restorePreliminaryState() {
    var raw = form.getAttribute('data-preliminary');
    if (!raw) return;
    var data = null;
    try {
      data = JSON.parse(raw);
    } catch (_) {
      return;
    }
    if (!data || !data.triage_id) return;
    var level = urgencyToLevel(data.triage_level || data.classification_label);
    if (level !== 'non_urgent' && level !== 'urgent') return;
    var storedComplaint = String(data.chief_complaint || '').trim();
    if (storedComplaint && complaintText() && storedComplaint !== complaintText()) {
      return;
    }
    if (storedComplaint && !complaintText() && complaintEl) {
      complaintEl.value = storedComplaint;
    }
    triageId = parseInt(data.triage_id, 10) || 0;
    triageLevel = level;
    triageComplaint = complaintText();
    awaitingSecondClick = triageId > 0;
    if (awaitingSecondClick) {
      showContinueUi(level, data.classification_label || '');
    }
  }

  async function submitSymptoms(complaint, stage) {
    var fd = new FormData(form);
    fd.set('chief_complaint', complaint);
    fd.set('csrf_token', csrf());
    fd.set('stage', stage);
    if (triageId > 0) {
      fd.set('triage_id', String(triageId));
    }

    var controller = new AbortController();
    var timer = window.setTimeout(function () {
      controller.abort();
    }, ANALYZE_TIMEOUT_MS);

    try {
      var res = await fetch(base() + '/app/api/patient/submit_symptoms_review.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        signal: controller.signal,
        headers: { 'X-MC-No-Loader': '1' },
      });
      window.clearTimeout(timer);
      var data = await res.json().catch(function () { return null; });
      return { res: res, data: data };
    } catch (err) {
      window.clearTimeout(timer);
      throw err;
    }
  }

  async function submitForReview(complaint, options) {
    options = options || {};

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = i18n('submitting');
    }

    try {
      var result = await submitSymptoms(complaint, 'continue');
      var data = result.data;
      if (!data || !data.success) {
        showAlert('error', (data && data.message) || i18n('err_submit'));
        return false;
      }

      var payload = data.data || data;
      if (payload.preview) {
        // Server refused to assign — keep the patient on step 1.
        awaitingSecondClick = true;
        triageId = parseInt(payload.triage_id, 10) || triageId;
        showContinueUi(triageLevel, payload.classification_label || '');
        return false;
      }
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
        var bookedTriageId = payload.triage_id ? payload.triage_id : 0;
        showAlert('error', urgMsg);
        if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showUrgent === 'function') {
          window.mcPatientUrgencyModal.showUrgent(urgMsg, payload.book_url || '', {
            complaint: urgentComplaint,
            triageId: bookedTriageId,
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
    } catch (err) {
      if (err && err.name === 'AbortError') {
        showAlert('error', i18n('err_timeout'));
      } else {
        showAlert('error', i18n('err_network'));
      }
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
      var result = await submitSymptoms(complaint, 'preview');
      var json = result.data;
      if (!json) {
        showAlert('error', i18n('err_analyze'));
        return false;
      }

      var payload = (json && (json.data || json)) || {};
      if (!result.res.ok || json.success === false) {
        showAlert('error', json.message || i18n('err_analyze'));
        return false;
      }

      var level = urgencyToLevel(payload.triage_level || payload.classification_label || extractUrgency(payload));
      if (!level) {
        showAlert('error', i18n('err_triage_level'));
        return false;
      }

      triageComplaint = complaint;
      triageLevel = level;
      triageId = parseInt(payload.triage_id, 10) || 0;

      if (level === 'emergency' || payload.emergency) {
        awaitingSecondClick = false;
        hideContinueUi();
        if (!payload.emergency) {
          var emergencySubmitted = await submitForReview(complaint, { skipOutcomeModal: true });
          if (emergencySubmitted) {
            presentTriageOutcome(level, complaint, payload);
          } else {
            clearTriageState();
          }
          return emergencySubmitted;
        }
        presentTriageOutcome(level, complaint, payload);
        showAlert('error', i18n('em_submit'));
        if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showEmergency === 'function') {
          window.mcPatientUrgencyModal.showEmergency(i18n('em_submit'));
        }
        return true;
      }

      if (triageId <= 0) {
        showAlert('error', i18n('err_submit'));
        clearTriageState();
        return false;
      }

      awaitingSecondClick = true;
      showContinueUi(level, payload.classification_label || '');
      presentTriageOutcome(level, complaint, payload);
      updateSubmitButtonLabel();
      return true;
    } catch (err) {
      if (err && err.name === 'AbortError') {
        showAlert('error', i18n('err_timeout'));
      } else {
        showAlert('error', i18n('err_network'));
      }
      clearTriageState();
      return false;
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
  restorePreliminaryState();

  window.addEventListener('medconnect:patient-ui-lang', function () {
    refreshSubmitLabels();
    updateSubmitButtonLabel();
    if (awaitingSecondClick && continueHintEl) {
      continueHintEl.textContent = CONTINUE_MSG;
    }
  });

  // Deferred i18n loads after this script; re-sync label once translations are available.
  window.addEventListener('load', function () {
    refreshSubmitLabels();
    if (submitBtn && !submitBtn.disabled) updateSubmitButtonLabel();
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (submitInFlight) return;
    submitInFlight = true;

    try {
      if (!awaitingSecondClick) {
        clearAlert();
      }

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
    } finally {
      submitInFlight = false;
    }
  });
})();
