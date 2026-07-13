/**
 * Registration — silent Chief Complaint NLP (patient never sees technical results).
 * Runs existing PHP proxy → FastAPI analyze-medical-profile pipeline in the background.
 */
(function (global) {
  'use strict';

  const MIN_CHARS = 5;
  const ANALYZE_TIMEOUT_MS = 120000;
  const LOADING_STEPS = [
    'Processing your symptoms…',
    'Identifying medical keywords…',
    'Assessing urgency…',
    'Preparing your consultation…',
  ];

  let loadingTimer = null;
  let analyzeController = null;
  let lastAnalyzedText = '';
  let analysisOk = false;
  let analysisInFlight = false;
  let bypassGate = false;
  let lastResult = null;

  function baseUrl() {
    return String(global.APP_BASE || '').replace(/\/$/, '');
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function els() {
    return {
      textarea: document.getElementById('chief-complaint'),
      overlay: document.getElementById('reg-nlp-overlay'),
      steps: document.getElementById('reg-nlp-overlay-steps'),
      progressBar: document.getElementById('reg-nlp-overlay-progress'),
      submitBtn: document.getElementById('reg-submit'),
      submitHint: document.getElementById('step2-submit-hint'),
      consent: document.getElementById('consent-checkbox'),
      allergies: document.getElementById('allergies'),
      allergyNo: document.getElementById('allergy-no'),
      err: document.getElementById('chief-complaint-error'),
    };
  }

  function meaningfulLength(text) {
    return String(text || '').replace(/\s+/g, '').length;
  }

  function normalizeComplaint(text) {
    return String(text || '').trim().replace(/\s+/g, ' ');
  }

  function getAllergiesText() {
    const { allergies, allergyNo } = els();
    if (allergyNo && allergyNo.checked) return 'No Known Allergies';
    return allergies && allergies.value.trim() ? allergies.value.trim() : 'No Known Allergies';
  }

  function setAnalysisState(partial) {
    if (partial.ok !== undefined) analysisOk = !!partial.ok;
    if (partial.inFlight !== undefined) analysisInFlight = !!partial.inFlight;
    if (partial.bypass !== undefined) bypassGate = !!partial.bypass;
    updateSubmitGate();
  }

  function updateSubmitGate() {
    const { submitBtn, submitHint, consent } = els();
    if (!submitBtn) return;

    const consentOk = !!(consent && consent.checked);
    const loadingSubmit = submitBtn.dataset.loading === '1';
    const nlpBlocking = analysisInFlight;
    const complaintOk = validateComplaint(
      normalizeComplaint((els().textarea && els().textarea.value) || ''),
      { showErrorMsg: false }
    );

    if (loadingSubmit || nlpBlocking) {
      submitBtn.disabled = true;
    } else if (!consentOk || !complaintOk) {
      submitBtn.disabled = true;
    } else {
      submitBtn.disabled = false;
    }

    if (!submitHint) return;
    if (loadingSubmit) return;

    if (analysisInFlight) {
      submitHint.textContent = 'Preparing your registration…';
      submitHint.classList.remove('is-ready');
      return;
    }

    if (!consentOk) {
      submitHint.textContent = 'Please accept the privacy consent to enable submission.';
      submitHint.classList.remove('is-ready');
      return;
    }

    if (!complaintOk) {
      submitHint.textContent = 'Please describe your current health concern before submitting.';
      submitHint.classList.remove('is-ready');
      return;
    }

    submitHint.textContent = 'Consent accepted. You can submit your registration.';
    submitHint.classList.add('is-ready');
  }

  function clearLoadingAnimation() {
    if (loadingTimer) {
      clearInterval(loadingTimer);
      loadingTimer = null;
    }
  }

  function paintOverlaySteps(active) {
    const { steps } = els();
    if (!steps) return;
    steps.innerHTML = LOADING_STEPS.map(function (label, idx) {
      const done = idx < active;
      const current = idx === active;
      const icon = done ? '✓' : current ? '…' : '○';
      return (
        '<li class="reg-nlp-overlay__step' +
        (done ? ' is-done' : '') +
        (current ? ' is-active' : '') +
        '"><span class="reg-nlp-overlay__step-icon" aria-hidden="true">' +
        icon +
        '</span><span>' +
        escapeHtml(label) +
        '</span></li>'
      );
    }).join('');
  }

  function showOverlay(visible) {
    const L = global.MedConnectGlobalLoader || global.MedConnectLoader;
    if (L && typeof L.showFormal === 'function') {
      if (visible) {
        L.showFormal({
          preset: 'ai',
          status: 'Processing your symptoms…',
          substatus: 'Running medical NLP pipeline…',
        });
      } else {
        L.hideFormal();
      }
      return;
    }

    if (L && typeof L.showPersistent === 'function') {
      if (visible) {
        L.showPersistent('reg-nlp-overlay', { preset: 'ai' });
      } else {
        L.hidePersistent('reg-nlp-overlay');
      }
      return;
    }

    const { overlay } = els();
    if (!overlay) return;
    if (visible) {
      overlay.hidden = false;
      overlay.setAttribute('aria-hidden', 'false');
      document.body.classList.add('reg-nlp-overlay-open');
      clearLoadingAnimation();
      let active = 0;
      paintOverlaySteps(active);
      loadingTimer = setInterval(function () {
        active = Math.min(active + 1, LOADING_STEPS.length - 1);
        paintOverlaySteps(active);
      }, 900);
    } else {
      clearLoadingAnimation();
      overlay.hidden = true;
      overlay.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('reg-nlp-overlay-open');
    }
  }

  function extractSymptoms(data) {
    const clinical = data.clinical_urgency || {};
    const fromClinical = Array.isArray(clinical.detected_symptoms) ? clinical.detected_symptoms : [];
    if (fromClinical.length) return fromClinical;

    const keywords = data.translated_keywords || data.detected_keywords || [];
    if (Array.isArray(keywords) && keywords.length) {
      return keywords
        .map(function (k) {
          if (typeof k === 'string') return k;
          return k.english || k.term || k.standardized_term || '';
        })
        .filter(Boolean);
    }

    const terms = data.term_results || [];
    return terms
      .filter(function (t) {
        return t && (t.display_status === 'valid' || t.matched);
      })
      .map(function (t) {
        return t.standardized_term || t.english_term || t.original_local || '';
      })
      .filter(Boolean);
  }

  function extractConditions(data) {
    const clinical = data.clinical_urgency || {};
    const fromClinical = Array.isArray(clinical.detected_conditions) ? clinical.detected_conditions : [];
    if (fromClinical.length) return fromClinical;

    const matched = data.matched_records || [];
    return matched
      .map(function (m) {
        return m.condition || m.standard_term || m.name || m.matched_term || '';
      })
      .filter(Boolean)
      .slice(0, 5);
  }

  function extractConfidence(data) {
    const clinical = data.clinical_urgency || {};
    const assessment = data.confidence_assessment || {};

    if (clinical.confidence_display) return String(clinical.confidence_display);
    if (clinical.confidence_score != null && clinical.confidence_score !== '') {
      const n = Number(clinical.confidence_score);
      if (!Number.isNaN(n)) {
        const pct = n <= 1 ? Math.round(n * 100) : Math.round(n);
        return pct + '%';
      }
    }
    if (assessment.overall_confidence_display) return String(assessment.overall_confidence_display);
    if (assessment.score != null) {
      const n = Number(assessment.score);
      if (!Number.isNaN(n)) {
        const pct = n <= 1 ? Math.round(n * 100) : Math.round(n);
        return pct + '%';
      }
    }
    return '';
  }

  function extractUrgency(data) {
    const clinical = (data && data.clinical_urgency) || {};
    const raw = String(
      clinical.triage_display || clinical.urgency || clinical.classification || 'NON-URGENT'
    )
      .trim()
      .toUpperCase()
      .replace(/\s+/g, '-');

    if (raw.includes('EMERGENCY')) return 'EMERGENCY';
    if (raw.includes('URGENT') && !raw.includes('NON')) return 'URGENT';
    return 'NON-URGENT';
  }

  function storeResult(data, originalText) {
    const clinical = (data && data.clinical_urgency) || {};
    const urgency = extractUrgency(data || {});
    const payload = {
      clinical_urgency: clinical || null,
      urgency: urgency,
      original_complaint: normalizeComplaint(originalText),
      translated_english:
        (data && data.translated_english) ||
        (data && data.translation && data.translation.translated_text) ||
        '',
      detected_symptoms: extractSymptoms(data || {}),
      detected_conditions: extractConditions(data || {}),
      confidence: extractConfidence(data || {}),
      timestamp: new Date().toISOString(),
    };
    lastResult = payload;
    try {
      global.__regNlpLastResult = payload;
    } catch (_) { /* ignore */ }
    return payload;
  }

  function validateComplaint(text, { showErrorMsg } = {}) {
    const { err, textarea } = els();
    const trimmed = normalizeComplaint(text);
    let message = '';

    if (!trimmed || meaningfulLength(trimmed) < MIN_CHARS) {
      message = 'Please describe your current symptoms before submitting.';
    }

    if (showErrorMsg && err) {
      err.textContent = message;
    }
    if (textarea) textarea.classList.toggle('invalid', !!message && !!showErrorMsg);
    return !message;
  }

  async function waitForPythonService(maxWaitMs) {
    const deadline = Date.now() + (maxWaitMs || 20000);
    let lastReason = '';

    while (Date.now() < deadline) {
      try {
        const res = await fetch(baseUrl() + '/app/api/ai/service_status.php?start=1');
        const json = await res.json();
        const d = (json && (json.data || json)) || {};
        if (d.online) return { online: true, data: d };
        lastReason = d.reason || d.message || 'Python AI service not ready';
      } catch (_) {
        lastReason = 'Could not reach status API';
      }
      await new Promise(function (resolve) {
        setTimeout(resolve, 1200);
      });
    }

    return { online: false, reason: lastReason || 'Python AI service did not start in time' };
  }

  /**
   * Run NLP silently. Patient only sees the generic loading overlay (managed by caller
   * or via showOverlay here when options.showOverlay !== false).
   * @returns {Promise<{ok:boolean, urgency:string, result:object|null, bypass?:boolean}>}
   */
  async function runAnalysis(options) {
    options = options || {};
    const { textarea } = els();
    if (!textarea) {
      return { ok: false, urgency: 'NON-URGENT', result: null };
    }

    const text = normalizeComplaint(textarea.value);
    if (!validateComplaint(text, { showErrorMsg: true })) {
      setAnalysisState({ ok: false, inFlight: false, bypass: false });
      return { ok: false, urgency: 'NON-URGENT', result: null };
    }

    if (analysisInFlight && !options.force) {
      return { ok: false, urgency: 'NON-URGENT', result: null };
    }

    if (
      !options.force &&
      text === lastAnalyzedText &&
      analysisOk &&
      lastResult &&
      lastResult.urgency
    ) {
      return { ok: true, urgency: lastResult.urgency, result: lastResult };
    }

    if (analyzeController) {
      analyzeController.abort();
      analyzeController = null;
    }

    analyzeController = new AbortController();
    setAnalysisState({ ok: false, inFlight: true, bypass: false });

    if (options.showOverlay !== false) {
      showOverlay(true);
    }

    try {
      await waitForPythonService(20000);

      const body = new FormData();
      body.append('chief_complaint', text);
      body.append('existing_conditions', text);
      body.append('current_medications', text);
      body.append('allergies', getAllergiesText());

      const timer = setTimeout(function () {
        if (analyzeController) analyzeController.abort();
      }, ANALYZE_TIMEOUT_MS);

      const res = await fetch(baseUrl() + '/app/api/ai/analyze_medical_profile.php', {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        signal: analyzeController.signal,
      });
      clearTimeout(timer);

      let json;
      try {
        json = await res.json();
      } catch (_) {
        setAnalysisState({ ok: false, inFlight: false });
        return { ok: false, urgency: 'NON-URGENT', result: null, error: 'parse' };
      }

      const data = (json && (json.data || json)) || {};

      if (!res.ok || json.success === false) {
        if (data.clinical_urgency || data.preprocessing || data.translation) {
          lastAnalyzedText = text;
          const payload = storeResult(data, text);
          setAnalysisState({ ok: true, inFlight: false });
          return { ok: true, urgency: payload.urgency, result: payload };
        }
        setAnalysisState({ ok: false, inFlight: false });
        return {
          ok: false,
          urgency: 'NON-URGENT',
          result: null,
          error: json.message || 'analysis_failed',
        };
      }

      lastAnalyzedText = text;
      const payload = storeResult(data, text);
      setAnalysisState({ ok: true, inFlight: false });
      return { ok: true, urgency: payload.urgency, result: payload };
    } catch (err) {
      if (err && err.name === 'AbortError') {
        setAnalysisState({ ok: false, inFlight: false });
        return { ok: false, urgency: 'NON-URGENT', result: null, error: 'aborted' };
      }
      setAnalysisState({ ok: false, inFlight: false });
      return { ok: false, urgency: 'NON-URGENT', result: null, error: 'network' };
    } finally {
      analyzeController = null;
      if (options.showOverlay !== false && options.keepOverlay !== true) {
        showOverlay(false);
      }
    }
  }

  function allowContinueWithoutNlp() {
    setAnalysisState({ ok: true, bypass: true, inFlight: false });
    lastResult = {
      urgency: 'NON-URGENT',
      bypass: true,
      original_complaint: normalizeComplaint((els().textarea && els().textarea.value) || ''),
      timestamp: new Date().toISOString(),
    };
    try {
      global.__regNlpLastResult = lastResult;
    } catch (_) { /* ignore */ }
    return lastResult;
  }

  function onComplaintEdited() {
    const { textarea, err } = els();
    analysisOk = false;
    bypassGate = false;
    lastAnalyzedText = '';
    lastResult = null;

    if (analyzeController) {
      analyzeController.abort();
      analyzeController = null;
    }
    if (analysisInFlight) {
      analysisInFlight = false;
    }

    if (err) err.textContent = '';
    if (textarea) textarea.classList.remove('invalid');
    updateSubmitGate();
  }

  function isReadyForSubmit() {
    // NLP runs on submit; only require a usable complaint + consent (gate handles consent)
    const text = normalizeComplaint((els().textarea && els().textarea.value) || '');
    return validateComplaint(text, { showErrorMsg: false }) || bypassGate || analysisOk;
  }

  function getLastUrgency() {
    if (lastResult && lastResult.urgency) return lastResult.urgency;
    if (global.__regNlpLastResult && global.__regNlpLastResult.urgency) {
      return global.__regNlpLastResult.urgency;
    }
    return 'NON-URGENT';
  }

  function init() {
    const { textarea, consent } = els();
    if (!textarea) return;

    textarea.addEventListener('input', onComplaintEdited);
    if (consent) {
      consent.addEventListener('change', updateSubmitGate);
    }
    updateSubmitGate();
  }

  global.MedConnectRegisterNlp = {
    init: init,
    runAnalysis: runAnalysis,
    showOverlay: showOverlay,
    hideOverlay: function () {
      showOverlay(false);
    },
    isReadyForSubmit: isReadyForSubmit,
    updateSubmitGate: updateSubmitGate,
    isAnalyzing: function () {
      return analysisInFlight;
    },
    getLastUrgency: getLastUrgency,
    getLastResult: function () {
      return lastResult || global.__regNlpLastResult || null;
    },
    allowContinueWithoutNlp: allowContinueWithoutNlp,
    validateComplaint: validateComplaint,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window);
