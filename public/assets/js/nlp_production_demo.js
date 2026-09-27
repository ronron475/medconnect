(function () {
  'use strict';

  var form = document.getElementById('nlp-prod-form');
  var input = document.getElementById('nlp-prod-input');
  var submitBtn = document.getElementById('nlp-prod-submit');
  var clearBtn = document.getElementById('nlp-prod-clear');
  var statusEl = document.getElementById('nlp-prod-status');
  var resultEl = document.getElementById('nlp-prod-result');
  if (!form || !input || !submitBtn || !resultEl) return;

  function baseUrl() {
    var base = typeof window.APP_BASE === 'string' ? window.APP_BASE : '';
    return base.replace(/\/$/, '');
  }

  function textList(items) {
    if (!Array.isArray(items)) return [];
    return items.map(function (item) {
      if (typeof item === 'string') return item.trim();
      if (!item || typeof item !== 'object') return '';
      return String(item.label || item.name || item.term || item.symptom || item.text || item.concept || '').trim();
    }).filter(Boolean);
  }

  function badgeClass(label) {
    var key = String(label || '').toUpperCase();
    if (key === 'EMERGENCY') return 'nlp-prod-badge nlp-prod-badge--emergency';
    if (key === 'URGENT') return 'nlp-prod-badge nlp-prod-badge--urgent';
    if (key === 'NON-URGENT') return 'nlp-prod-badge nlp-prod-badge--routine';
    return 'nlp-prod-badge nlp-prod-badge--hold';
  }

  function row(label, value) {
    var text = String(value || '').trim();
    if (!text) return '';
    return '<div><dt>' + label + '</dt><dd>' + escapeHtml(text) + '</dd></div>';
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function showStatus(message) {
    statusEl.hidden = !message;
    statusEl.textContent = message || '';
  }

  function render(json) {
    var summary = json.summary || {};
    var urgency = json.clinical_urgency || {};
    var assessment = json.assessment || {};
    var needsComplaint = !!(assessment.needs_valid_complaint || summary.needs_valid_complaint);
    var label = needsComplaint
      ? 'Needs a health complaint'
      : (urgency.triage_display || summary.classification || 'Result');
    var symptoms = textList(summary.detected_symptoms);
    var concepts = textList(summary.standardized_medical_concepts);
    var flags = textList(summary.red_flags);
    var message = needsComplaint ? (assessment.patient_message || '') : '';
    var service = json.service || {};
    var serviceLine = typeof service.online === 'boolean'
      ? (service.online ? 'Python AI service online' : 'Python AI service offline')
      : '';

    resultEl.hidden = false;
    resultEl.innerHTML =
      '<p class="' + badgeClass(needsComplaint ? 'HOLD' : label) + '">' + escapeHtml(label) + '</p>' +
      '<dl class="nlp-prod-grid">' +
        row('Original', summary.original_chief_complaint) +
        row('Language', summary.detected_language) +
        row('English', summary.english_translation) +
        row('Symptoms', symptoms.join(', ')) +
        row('Concepts', concepts.join(', ')) +
        row('Red flags', flags.join(', ')) +
        row('Reason', message || summary.clinical_reasoning || summary.reason) +
        row('Recommended action', needsComplaint ? '' : (summary.recommended_action || urgency.recommended_action)) +
        row('Confidence', needsComplaint ? '' : (urgency.confidence_display || (summary.confidence ? summary.confidence + '%' : ''))) +
        row('Engine', json.engine_chain || summary.engine_chain) +
        row('AI service', serviceLine) +
      '</dl>';
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var text = input.value.trim();
    if (!text) {
      showStatus('Enter a complaint first.');
      resultEl.hidden = true;
      return;
    }
    submitBtn.disabled = true;
    showStatus('');
    var body = new FormData();
    body.append('chief_complaint', text);
    fetch(baseUrl() + '/app/api/ai/assess_chief_complaint.php', {
      method: 'POST',
      body: body,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().then(function (json) {
        return { ok: res.ok, json: json };
      });
    }).then(function (payload) {
      var json = payload.json || {};
      if (!payload.ok || json.success === false) {
        showStatus(json.message || 'Analysis failed.');
        resultEl.hidden = true;
        return;
      }
      render(json);
    }).catch(function () {
      showStatus('Could not reach the NLP service.');
      resultEl.hidden = true;
    }).finally(function () {
      submitBtn.disabled = false;
    });
  });

  clearBtn.addEventListener('click', function () {
    input.value = '';
    showStatus('');
    resultEl.hidden = true;
    resultEl.innerHTML = '';
    input.focus();
  });

  document.querySelectorAll('.nlp-prod-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      input.value = chip.getAttribute('data-text') || '';
      input.focus();
    });
  });
})();
