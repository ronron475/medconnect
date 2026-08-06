(function () {
  'use strict';

  const config = window.CDS_DEMO || {};
  const base = config.apiBase || window.APP_BASE || '';
  const apiUrl = base + '/app/api/ai/cds_triage_demo.php';
  const statusUrl = base + '/app/api/ai/service_status.php?no_start=1';
  const phpNlpPrimary = config.phpNlpPrimary !== false;

  const form = document.getElementById('cds-demo-form');
  const complaintEl = document.getElementById('chief-complaint');
  const analyzeBtn = document.getElementById('btn-analyze');
  const clearBtn = document.getElementById('btn-clear');
  const serviceStatusEl = document.getElementById('cds-service-status');
  const feedbackEl = document.getElementById('cds-feedback');
  const resultsEl = document.getElementById('cds-results');

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function showFeedback(message, type) {
    if (!feedbackEl) return;
    feedbackEl.hidden = false;
    feedbackEl.className = 'cds-feedback cds-feedback--' + (type || 'info');
    feedbackEl.textContent = message;
  }

  function hideFeedback() {
    if (feedbackEl) {
      feedbackEl.hidden = true;
      feedbackEl.textContent = '';
    }
  }

  function badgeClass(classification) {
    const c = String(classification || '').toUpperCase();
    if (c === 'EMERGENCY') return 'cds-badge cds-badge--emergency';
    if (c === 'URGENT') return 'cds-badge cds-badge--urgent';
    return 'cds-badge cds-badge--routine';
  }

  function badgeIcon(classification) {
    const c = String(classification || '').toUpperCase();
    if (c === 'EMERGENCY') return '🔴';
    if (c === 'URGENT') return '🟡';
    return '🟢';
  }

  function renderList(items, emptyText) {
    if (!Array.isArray(items) || items.length === 0) {
      return '<p class="cds-card__value">' + escapeHtml(emptyText || 'None') + '</p>';
    }
    return (
      '<ul class="cds-list">' +
      items.map(function (item) {
        return '<li>' + escapeHtml(String(item)) + '</li>';
      }).join('') +
      '</ul>'
    );
  }

  function renderTags(items, danger) {
    if (!Array.isArray(items) || items.length === 0) {
      return '<span class="cds-tag cds-tag--muted">None</span>';
    }
    return items
      .map(function (item) {
        const cls = danger ? 'cds-tag cds-tag--danger' : 'cds-tag';
        return '<span class="' + cls + '">' + escapeHtml(String(item)) + '</span>';
      })
      .join('');
  }

  function formatDuration(duration) {
    if (!duration) return 'Not detected';
    if (typeof duration === 'string') return duration;
    if (typeof duration === 'object') {
      const parts = [];
      if (duration.label) parts.push(duration.label);
      if (duration.value != null && duration.unit) {
        parts.push(duration.value + ' ' + duration.unit);
      }
      if (duration.text) parts.push(duration.text);
      if (duration.normalized) parts.push('(' + duration.normalized + ')');
      return parts.length ? parts.join(' ') : 'Detected';
    }
    return String(duration);
  }

  function formatPainTemp(pain, temp) {
    function fmtPain(p) {
      if (!p) return 'Pain: n/a';
      if (typeof p === 'string' || typeof p === 'number') return 'Pain ' + p + '/10';
      if (typeof p === 'object') {
        if (p.label) return 'Pain: ' + p.label;
        if (p.score != null) return 'Pain ' + p.score + '/10';
        if (p.band) return 'Pain: ' + p.band;
      }
      return 'Pain: detected';
    }
    function fmtTemp(t) {
      if (!t) return 'Temp: n/a';
      if (typeof t === 'string' || typeof t === 'number') return 'Temp ' + t;
      if (typeof t === 'object') {
        if (t.label) return 'Temp: ' + t.label;
        if (t.value != null) return 'Temp ' + t.value;
      }
      return 'Temp: detected';
    }
    return fmtPain(pain) + ' · ' + fmtTemp(temp);
  }

  function fetchWithTimeout(url, timeoutMs) {
    if (typeof AbortController === 'undefined') {
      return fetch(url);
    }
    const controller = new AbortController();
    const timer = setTimeout(function () {
      controller.abort();
    }, timeoutMs);
    return fetch(url, { signal: controller.signal }).finally(function () {
      clearTimeout(timer);
    });
  }

  function renderPhpPrimaryStatus(pythonNote) {
    if (!serviceStatusEl) return;
    serviceStatusEl.hidden = false;
    serviceStatusEl.className = 'cds-demo-status cds-demo-status--ok';
    serviceStatusEl.innerHTML =
      '<div class="cds-status-line"><strong>PHP rule-based CDS engine active</strong> — primary triage path (not a fallback).</div>' +
      (pythonNote
        ? '<div class="cds-status-line cds-status-line--muted">' + pythonNote + '</div>'
        : '<div class="cds-status-line cds-status-line--muted">Python AI service is optional for this demo.</div>');
  }

  async function refreshServiceStatus() {
    if (!serviceStatusEl) return;
    if (phpNlpPrimary) {
      renderPhpPrimaryStatus('Checking optional Python AI service status…');
    }
    try {
      const res = await fetchWithTimeout(statusUrl, 10000);
      if (!res.ok) {
        throw new Error('HTTP ' + res.status);
      }
      const json = await res.json();
      const d = (json && (json.data || json)) || {};
      const online = !!d.online;

      serviceStatusEl.hidden = false;
      serviceStatusEl.className = 'cds-demo-status ' + (online ? 'cds-demo-status--ok' : 'cds-demo-status--warn');

      if (online) {
        serviceStatusEl.innerHTML =
          '<div class="cds-status-line"><strong>PHP rule-based CDS engine active</strong> — primary triage path (not a fallback).</div>' +
          '<div class="cds-status-line cds-status-line--muted">Python AI service also online on port ' +
          escapeHtml(d.port || 8765) +
          ' (optional; CDS uses PHP NLP).</div>';
      } else {
        serviceStatusEl.innerHTML =
          '<div class="cds-status-line"><strong>PHP rule-based CDS engine active</strong> — primary triage path (not a fallback).</div>' +
          '<div class="cds-status-line cds-status-line--muted">Python AI offline (not required for CDS triage).</div>';
      }
    } catch (e) {
      renderPhpPrimaryStatus(
        'Optional Python AI status check unavailable — CDS triage still runs on PHP NLP.'
      );
    }
  }

  function renderResults(payload) {
    const assessment = payload.assessment || {};
    const summary = payload.summary || {};
    const triage = assessment.triage || {};
    const validation =
      (assessment.clinical_recommendation && assessment.clinical_recommendation.validation) ||
      triage.validation ||
      {};

    const classification = summary.classification || triage.triage_display || 'NON-URGENT';
    const confidence = summary.confidence != null ? summary.confidence : (assessment.confidence && assessment.confidence.score);
    const symptoms = assessment.detected_symptoms || triage.detected_symptoms || [];
    const redFlags = triage.red_flags || (assessment.clinical_recommendation && assessment.clinical_recommendation.red_flags) || [];
    const riskFactors = triage.risk_factors || (assessment.clinical_recommendation && assessment.clinical_recommendation.risk_factors) || [];
    const reason =
      summary.reason ||
      triage.clinical_reasoning ||
      triage.reason ||
      (assessment.clinical_recommendation && assessment.clinical_recommendation.reason) ||
      '';

    const engine = summary.engine || assessment.engine || 'unknown';
    const serviceUsed = summary.service_used != null ? summary.service_used : assessment.service_used;
    const clinicalContext = assessment.clinical_context || {};
    const contextRule = clinicalContext.rule_name || '';
    const evaluatedContext = clinicalContext.evaluated_context || [];
    const validationPassed = summary.validation_passed != null ? summary.validation_passed : validation.passed;
    const winningRule = summary.winning_rule || validation.winning_rule || contextRule || '';

    resultsEl.hidden = false;
    resultsEl.innerHTML =
      '<div class="cds-result-head">' +
      '<span class="' +
      badgeClass(classification) +
      '">' +
      badgeIcon(classification) +
      ' ' +
      escapeHtml(classification) +
      '</span>' +
      '<span class="cds-confidence">Confidence: <strong>' +
      escapeHtml(confidence != null ? confidence + '%' : '—') +
      '</strong></span>' +
      '</div>' +
      (reason
        ? '<p class="cds-reason">' + escapeHtml(reason) + '</p>'
        : '') +
      '<div class="cds-grid">' +
      '<div class="cds-card"><p class="cds-card__label">Recommended action</p><p class="cds-card__value">' +
      escapeHtml(summary.recommended_action || assessment.recommended_action || '—') +
      '</p></div>' +
      '<div class="cds-card"><p class="cds-card__label">Language</p><p class="cds-card__value">' +
      escapeHtml(summary.detected_language || assessment.detected_language || 'unknown') +
      '</p></div>' +
      '<div class="cds-card"><p class="cds-card__label">Engine</p><p class="cds-card__value">' +
      escapeHtml(engine) +
      (serviceUsed ? ' (Python — not used for CDS)' : ' (PHP rule-based)') +
      '</p></div>' +
      '<div class="cds-card"><p class="cds-card__label">Severity score</p><p class="cds-card__value">' +
      escapeHtml(summary.severity_score != null ? summary.severity_score : triage.severity_score || 0) +
      '</p></div>' +
      '<div class="cds-card"><p class="cds-card__label">Duration</p><p class="cds-card__value">' +
      escapeHtml(formatDuration(triage.duration)) +
      '</p></div>' +
      '<div class="cds-card"><p class="cds-card__label">Pain / Temp</p><p class="cds-card__value">' +
      escapeHtml(formatPainTemp(triage.pain_scale, triage.temperature)) +
      '</p></div>' +
      '</div>' +
      '<h3 class="cds-section-title">Detected symptoms</h3>' +
      '<div class="cds-tags">' +
      renderTags(symptoms, false) +
      '</div>' +
      '<h3 class="cds-section-title">Red flags</h3>' +
      '<div class="cds-tags">' +
      renderTags(redFlags, true) +
      '</div>' +
      '<h3 class="cds-section-title">Risk factors</h3>' +
      renderList(riskFactors, 'None detected') +
      '<h3 class="cds-section-title">Clinical context evaluated</h3>' +
      (evaluatedContext.length
        ? renderList(evaluatedContext, 'None listed')
        : '<p class="cds-card__value">' + escapeHtml(contextRule || 'Combined symptom assessment') + '</p>') +
      '<h3 class="cds-section-title">Self-validation</h3>' +
      '<div class="cds-card"><p class="cds-card__value">' +
      (validationPassed === false ? '⚠ Review needed' : '✓ Passed') +
      (winningRule ? ' · Rule: ' + escapeHtml(winningRule) : '') +
      (summary.needs_provider_review || triage.needs_provider_review ? ' · Provider review flagged' : '') +
      '</p></div>' +
      (assessment.english_translation
        ? '<h3 class="cds-section-title">English translation</h3><p class="cds-reason">' +
          escapeHtml(assessment.english_translation) +
          '</p>'
        : '') +
      '<details class="cds-json-toggle"><summary>Pipeline debug trace</summary><pre class="cds-json">' +
      escapeHtml(JSON.stringify(payload.pipeline_debug || assessment.pipeline_debug || null, null, 2)) +
      '</pre></details>' +
      '<details class="cds-json-toggle"><summary>Raw JSON response</summary><pre class="cds-json">' +
      escapeHtml(JSON.stringify(payload, null, 2)) +
      '</pre></details>';
  }

  async function analyzeComplaint() {
    const text = (complaintEl && complaintEl.value || '').trim();
    if (!text) {
      showFeedback('Enter a chief complaint first.', 'error');
      return;
    }

    hideFeedback();
    if (resultsEl) resultsEl.hidden = true;
    if (analyzeBtn) {
      analyzeBtn.disabled = true;
      analyzeBtn.textContent = 'Analyzing…';
    }

    try {
      const body = new FormData();
      body.append('chief_complaint', text);
      body.append('debug', '1');

      const res = await fetch(apiUrl, { method: 'POST', body: body });
      const json = await res.json();

      if (!json.success) {
        showFeedback(json.message || json.error || 'Analysis failed.', 'error');
        return;
      }

      renderResults(json.data || json);
    } catch (e) {
      showFeedback('Network error — could not reach the triage API.', 'error');
    } finally {
      if (analyzeBtn) {
        analyzeBtn.disabled = false;
        analyzeBtn.textContent = 'Analyze triage';
      }
    }
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      analyzeComplaint();
    });
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      if (complaintEl) complaintEl.value = '';
      hideFeedback();
      if (resultsEl) {
        resultsEl.hidden = true;
        resultsEl.innerHTML = '';
      }
    });
  }

  document.querySelectorAll('.cds-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      const text = chip.getAttribute('data-text') || '';
      if (complaintEl) complaintEl.value = text;
      hideFeedback();
    });
  });

  refreshServiceStatus();
  setInterval(refreshServiceStatus, 30000);
})();
