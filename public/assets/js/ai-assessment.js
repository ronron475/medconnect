/**
 * AI-Assisted Medical Assessment — patient triage UI
 */
(function (global) {
  'use strict';

  const STEPS = [
    'Analyzing Symptoms…',
    'Translating Language…',
    'Matching Medical Conditions…',
    'Calculating Confidence…',
    'Generating Assessment…',
    'Assessment Complete',
  ];

  let lastAssessment = null;
  let analyzeController = null;

  function baseUrl() {
    return (global.APP_BASE || global.ASSET_BASE || '').replace(/\/$/, '');
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function getFormInputs() {
    const form = document.getElementById('patientTriageForm');
    if (!form) return { complaint: '', symptoms: [] };

    const complaint = (form.querySelector('#chief_complaint')?.value || '').trim();
    const symptoms = Array.from(form.querySelectorAll('input[name="symptoms[]"]:checked'))
      .map((el) => el.value)
      .filter(Boolean);

    return { complaint, symptoms };
  }

  function renderProcessing(panel, activeIndex) {
    const items = STEPS.map((label, idx) => {
      let cls = 'ai-assessment-step';
      if (idx < activeIndex) cls += ' is-done';
      else if (idx === activeIndex) cls += ' is-active';
      return (
        '<li class="' + cls + '">' +
          '<span class="ai-assessment-step__dot" aria-hidden="true"></span>' +
          '<span>' + escapeHtml(label) + '</span>' +
        '</li>'
      );
    }).join('');

    panel.innerHTML =
      '<div class="ai-assessment-processing" role="status" aria-live="polite" aria-busy="true">' +
        '<p class="ai-assessment-processing__title">AI Medical Assessment in Progress</p>' +
        '<ul class="ai-assessment-steps">' + items + '</ul>' +
      '</div>';
  }

  function severityBadgeClass(severity) {
    const key = String(severity || 'mild').toLowerCase();
    if (key === 'severe') return 'severity-badge--severe';
    if (key === 'moderate') return 'severity-badge--moderate';
    return 'severity-badge--mild';
  }

  function renderAssessment(panel, assessment) {
    const symptoms = assessment.detected_symptoms || [];
    const conditions = assessment.possible_conditions || [];
    const confidence = assessment.confidence || {};
    const triage = assessment.triage || {};
    const severity = assessment.severity || {};
    const score = Number(confidence.score || 0);

    const symptomHtml = symptoms.length
      ? '<ul class="ai-symptom-list">' + symptoms.map((s) =>
          '<li>✔ ' + escapeHtml(s) + '</li>'
        ).join('') + '</ul>'
      : '<p class="ai-assessment-empty">No specific symptoms detected yet.</p>';

    const conditionHtml = conditions.length
      ? '<ul class="ai-condition-list">' + conditions.map((c) =>
          '<li>• ' + escapeHtml(c) + '</li>'
        ).join('') + '</ul>'
      : '<p class="ai-assessment-empty">No related conditions identified from current input.</p>';

    const translation = assessment.english_translation
      ? '<div class="ai-translation-box"><strong>English Translation:</strong> ' +
        escapeHtml(assessment.english_translation) + '</div>'
      : '';

    panel.innerHTML =
      '<div class="ai-assessment-card" role="region" aria-label="AI assessment summary">' +
        '<div class="ai-assessment-card__header">' +
          '<div>' +
            '<h4 class="ai-assessment-card__title">Assessment Summary</h4>' +
            '<p class="ai-assessment-card__subtitle">AI-assisted symptom analysis (not a diagnosis)</p>' +
          '</div>' +
        '</div>' +

        translation +

        '<div class="ai-assessment-section">' +
          '<div class="ai-assessment-section__label">Detected Symptoms</div>' +
          symptomHtml +
        '</div>' +

        '<div class="ai-assessment-section">' +
          '<div class="ai-assessment-section__label">Possible Related Conditions</div>' +
          conditionHtml +
        '</div>' +

        '<div class="ai-assessment-section">' +
          '<div class="ai-assessment-section__label">Confidence Score</div>' +
          '<div class="ai-confidence">' +
            '<div class="ai-confidence__bar" aria-hidden="true">' +
              '<div class="ai-confidence__fill" style="width:' + Math.max(0, Math.min(100, score)) + '%"></div>' +
            '</div>' +
            '<div class="ai-confidence__meta">' +
              '<span class="ai-confidence__score">Confidence Score: ' + escapeHtml(confidence.score_display || score + '%') + '</span>' +
              '<span class="ai-confidence__level">Confidence Level: ' + escapeHtml(confidence.level_label || '—') + '</span>' +
            '</div>' +
          '</div>' +
        '</div>' +

        '<div class="ai-assessment-section">' +
          '<div class="ai-assessment-section__label">Severity &amp; Triage</div>' +
          '<div class="ai-badge-row">' +
            '<span class="severity-badge ' + severityBadgeClass(severity.severity) + '">' +
              'Severity: ' + escapeHtml(severity.severity_label || 'Mild') +
            '</span>' +
            '<span class="triage-badge ' + escapeHtml(triage.triage_badge_class || 'triage-badge--green') + '">' +
              escapeHtml(triage.triage_icon || '') + ' ' + escapeHtml(triage.triage_display || 'NON-URGENT') +
            '</span>' +
          '</div>' +
        '</div>' +

        '<div class="ai-assessment-section">' +
          '<div class="ai-assessment-section__label">Recommended Action</div>' +
          '<div class="ai-recommendation-box">' +
            escapeHtml(assessment.recommended_action || triage.recommended_action || 'Monitor symptoms and consult if needed.') +
          '</div>' +
        '</div>' +

        '<div class="ai-disclaimer">' + escapeHtml(assessment.disclaimer || '') + '</div>' +
      '</div>';
  }

  function animateSteps(panel) {
    return new Promise(function (resolve) {
      let idx = 0;
      renderProcessing(panel, idx);
      const timer = setInterval(function () {
        idx += 1;
        if (idx >= STEPS.length - 1) {
          clearInterval(timer);
          renderProcessing(panel, STEPS.length - 1);
          setTimeout(resolve, 350);
          return;
        }
        renderProcessing(panel, idx);
      }, 420);
    });
  }

  async function runAssessment(options) {
    options = options || {};
    const panel = document.getElementById('aiAssessmentPanel');
    const btn = document.getElementById('btnRunAssessment');
    const loader = global.MedConnectGlobalLoader || global.MedConnectLoader;
    if (!panel) return null;

    const inputs = getFormInputs();
    if (!inputs.complaint && inputs.symptoms.length === 0) {
      panel.innerHTML = '<p class="ai-assessment-empty">Describe your complaint or select symptoms to run AI assessment.</p>';
      return null;
    }

    if (analyzeController) {
      analyzeController.abort();
    }
    analyzeController = new AbortController();

    if (btn) {
      btn.disabled = true;
      btn.dataset.originalText = btn.textContent;
      btn.textContent = 'Analyzing…';
    }

    if (loader && typeof loader.showFormal === 'function') {
      loader.showFormal({
        preset: 'assessment',
        status: 'Running AI assessment…',
        substatus: 'Analyzing symptoms and classifying urgency…',
      });
    } else if (!options.skipAnimation) {
      renderProcessing(panel, 0);
    }

    const fd = new FormData();
    fd.set('chief_complaint', inputs.complaint);
    const csrfToken = document.body?.dataset?.csrf || '';
    if (csrfToken) {
      fd.set('csrf_token', csrfToken);
    }
    inputs.symptoms.forEach(function (symptom) {
      fd.append('symptoms[]', symptom);
    });

    try {
      const res = await fetch(baseUrl() + '/app/api/patient/assess_symptoms.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        signal: analyzeController.signal,
      });

      let data;
      try {
        data = await res.json();
      } catch {
        panel.innerHTML = '<p class="ai-assessment-empty">Unexpected server response. Please try again.</p>';
        return null;
      }

      if (!data.success || !data.assessment) {
        panel.innerHTML = '<p class="ai-assessment-empty">' + escapeHtml(data.message || 'Assessment failed.') + '</p>';
        return null;
      }

      lastAssessment = data.assessment;
      renderAssessment(panel, data.assessment);
      global.dispatchEvent(new CustomEvent('mc:assessment-complete', { detail: data.assessment }));
      return data.assessment;
    } catch (err) {
      if (err && err.name === 'AbortError') {
        return null;
      }
      panel.innerHTML = '<p class="ai-assessment-empty">Network error. Please try again.</p>';
      return null;
    } finally {
      analyzeController = null;
      if (loader && typeof loader.hideFormal === 'function') {
        loader.hideFormal();
      }
      if (btn) {
        btn.disabled = false;
        btn.textContent = btn.dataset.originalText || 'Run AI Assessment';
      }
    }
  }

  function init() {
    const btn = document.getElementById('btnRunAssessment');
    const panel = document.getElementById('aiAssessmentPanel');
    if (!btn || !panel) return;

    btn.addEventListener('click', function () {
      runAssessment();
    });

    const form = document.getElementById('patientTriageForm');
    const complaintEl = form?.querySelector('#chief_complaint');
    if (complaintEl) {
      let debounceTimer;
      complaintEl.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          const value = complaintEl.value.trim();
          if (value.length >= 12) {
            runAssessment({ skipAnimation: false });
          }
        }, 1200);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  global.MedConnectAssessment = {
    run: runAssessment,
    getLast: function () { return lastAssessment; },
    render: function (target, assessment) {
      const panel = typeof target === 'string' ? document.getElementById(target) : target;
      if (!panel || !assessment) return;
      lastAssessment = assessment;
      renderAssessment(panel, assessment);
    },
  };
})(window);
