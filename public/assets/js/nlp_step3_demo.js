(function () {
  'use strict';

  const base = window.APP_BASE || '';
  const apiUrl = base + '/app/api/ai/analyze_medical_profile.php';

  const form = document.getElementById('nlp-demo-form');
  const conditionsEl = document.getElementById('existing-conditions');
  const allergiesEl = document.getElementById('known-allergies');
  const validateBtn = document.getElementById('btn-validate');
  const serviceStatusEl = document.getElementById('nlp-service-status');
  const feedbackEl = document.getElementById('nlp-feedback');
  const resultsEl = document.getElementById('nlp-results');

  async function waitForPythonService(maxWaitMs) {
    const deadline = Date.now() + (maxWaitMs || 45000);
    let lastReason = '';

    while (Date.now() < deadline) {
      try {
        const res = await fetch(base + '/app/api/ai/service_status.php?start=1');
        const json = await res.json();
        const d = (json && (json.data || json)) || {};
        if (d.online) {
          return { online: true, data: d };
        }
        lastReason = d.reason || d.message || 'Python AI service not ready';
      } catch (e) {
        lastReason = 'Could not reach status API';
      }
      await new Promise(function (resolve) {
        setTimeout(resolve, 1500);
      });
    }

    return { online: false, reason: lastReason || 'Python AI service did not start in time' };
  }

  async function refreshServiceStatus() {
    if (!serviceStatusEl) return;
    try {
      const controller = new AbortController();
      const timer = setTimeout(function () {
        controller.abort();
      }, 12000);
      const res = await fetch(base + '/app/api/ai/service_status.php?no_start=1', {
        signal: controller.signal,
      });
      clearTimeout(timer);
      const json = await res.json();
      const d = (json && (json.data || json)) || {};
      const online = !!d.online;
      const port = d.port || 8765;
      const model = d.model || d.groq_model || 'llama-3.3-70b-versatile';
      const reason = d.reason || d.message || 'Unknown error';

      serviceStatusEl.hidden = false;
      serviceStatusEl.className =
        'nlp-service-status ' + (online ? 'nlp-service-status--ok' : 'nlp-service-status--warn');

      if (online) {
        const groqOk = d.groq === 'connected' || d.groq_connected;
        const groqFailed = d.groq === 'failed' || (!groqOk && d.groq_configured);
        const groqError = d.groq_error || (d.diagnostics && d.diagnostics.groq_error) || '';
        const groqLine = groqOk
          ? '✓ Groq Connected'
          : groqFailed
            ? '⚠ Groq Failed' + (groqError ? ' — ' + groqError : '')
            : d.groq_configured
              ? '⚠ Groq configured (not verified)'
              : '✗ Groq not configured';
        serviceStatusEl.innerHTML =
          '<div class="nlp-status-line nlp-status-line--ok">✓ Python AI Service Online</div>' +
          '<div class="nlp-status-line ' +
          (groqOk ? 'nlp-status-line--ok' : 'nlp-status-line--warn') +
          '">' +
          escapeHtml(groqLine) +
          '</div>' +
          '<div class="nlp-status-line nlp-status-line--ok">✓ Port ' + port + ' Active</div>' +
          (groqOk
            ? '<div class="nlp-status-line">Model: <code>' +
              escapeHtml(model) +
              '</code></div>' +
              '<div class="nlp-status-line">Engine: <code>python-medical-profile-nlp</code></div>' +
              '<div class="nlp-status-line nlp-status-line--muted">✓ AI Translation Active</div>'
            : groqFailed
              ? '<div class="nlp-status-line nlp-status-line--fallback">Using Dictionary Fallback for Step 2</div>'
              : '');
      } else {
        const portLine = d.port_open
          ? '⚠ Port ' + port + ' open but health check failed'
          : '✗ Port ' + port + ' Not Reachable';
        const disabledNote = d.ai_service_enabled === false
          ? '<div class="nlp-status-line">Python AI disabled — using <code>PHP Validation Workflow</code> (production mode)</div>'
          : '';
        const diag = d.diagnostics || {};
        const venvNote = diag.venv_broken
          ? '<div class="nlp-status-line nlp-status-line--warn">Virtual env broken — run <code>ai_service/install_ai_dependencies.bat</code></div>'
          : '';
        const depsNote = !diag.dependencies_ok && diag.python_executable
          ? '<div class="nlp-status-line nlp-status-line--warn">Missing Python deps — run install script</div>'
          : '';
        serviceStatusEl.innerHTML =
          '<div class="nlp-status-line nlp-status-line--warn">✗ Python AI Service Offline</div>' +
          '<div class="nlp-status-line">Reason: <strong>' + escapeHtml(reason) + '</strong></div>' +
          '<div class="nlp-status-line nlp-status-line--warn">' + escapeHtml(portLine) + '</div>' +
          venvNote +
          depsNote +
          disabledNote +
          '<div class="nlp-status-line nlp-status-line--fallback">Python + Groq required — analyze will fail until service is online</div>' +
          '<div class="nlp-status-line nlp-status-line--muted">Auto-retry every 30s…</div>';
      }
    } catch (e) {
      serviceStatusEl.hidden = false;
      serviceStatusEl.className = 'nlp-service-status nlp-service-status--warn';
      serviceStatusEl.innerHTML =
        '<div class="nlp-status-line nlp-status-line--warn">✗ Python AI Service Offline</div>' +
        '<div class="nlp-status-line">Reason: <strong>Could not reach status API</strong></div>' +
        '<div class="nlp-status-line nlp-status-line--fallback">Python + Groq required for analyze</div>';
    }
  }

  refreshServiceStatus();
  fetch(base + '/app/api/ai/service_status.php?start=1').catch(function () {});
  setInterval(refreshServiceStatus, 30000);

  function showFeedback(message, type) {
    feedbackEl.hidden = false;
    feedbackEl.textContent = message;
    feedbackEl.className = 'nlp-feedback ' + type;
  }

  function hideFeedback() {
    feedbackEl.hidden = true;
    feedbackEl.textContent = '';
    feedbackEl.className = 'nlp-feedback';
  }

  function hideResults() {
    resultsEl.hidden = true;
    resultsEl.innerHTML = '';
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function statusBadge(status) {
    const s = String(status || 'unknown').toLowerCase();
    const cls =
      s === 'complete' ||
      s === 'matched' ||
      s === 'validated' ||
      s === 'accepted' ||
      s === 'confirmed' ||
      s === 'valid' ||
      s === 'found' ||
      s === 'approved' ||
      s === 'high' ||
      s === 'very_high' ||
      s === 'moderate'
        ? 'nlp-badge--ok'
        : s === 'partial' || s === 'medium' || s === 'low'
          ? 'nlp-badge--warn'
          : s === 'empty'
            ? 'nlp-badge--muted'
            : 'nlp-badge--bad';
    return '<span class="nlp-badge ' + cls + '">' + escapeHtml(s) + '</span>';
  }

  function confidenceBadge(level) {
    const l = String(level || 'none').toLowerCase();
    return statusBadge(l === 'high' || l === 'medium' ? l : l);
  }

  function buildClientSummary(data) {
    const direct = String(data.summary || '').trim();
    if (direct) {
      return direct;
    }

    const invalidDet = data.invalid_entry_detection || {};
    const userMsg = String(invalidDet.user_message || '').trim();
    const terms = data.term_results || [];

    if (!terms.length) {
      return userMsg || 'No medical terms were extracted from your input.';
    }

    const parts = terms.map(function (t) {
      const label = (t.term_type || (t.field === 'allergies' ? 'allergy' : 'condition'))
        .charAt(0)
        .toUpperCase() +
        (t.term_type || (t.field === 'allergies' ? 'allergy' : 'condition')).slice(1);
      const input = t.original_local || t.english_term || '—';
      if (t.display_status === 'valid') {
        return (
          label +
          ': ' +
          input +
          ' → ' +
          (t.standardized_term || t.english_term) +
          ' (verified)'
        );
      }
      return label + ': ' + input + ' (not in official dataset)';
    });

    return parts.join('. ') + '.';
  }

  function renderPreprocessBlock(title, block) {
    if (!block) return '';

    const kw = (block.keywords || []).join(', ') || '—';

    return (
      '<div class="nlp-prep-block">' +
      '<h4 class="nlp-prep-title">' +
      escapeHtml(title) +
      '</h4>' +
      '<dl class="nlp-prep-dl">' +
      '<div><dt>Original</dt><dd>' +
      escapeHtml(block.original || '—') +
      '</dd></div>' +
      '<div><dt>Normalized</dt><dd><code>' +
      escapeHtml(block.normalized || '—') +
      '</code></dd></div>' +
      '<div><dt>Cleaned</dt><dd><code>' +
      escapeHtml(block.cleaned || '—') +
      '</code></dd></div>' +
      '<div><dt>Extracted keywords</dt><dd><code>' +
      escapeHtml(kw) +
      '</code></dd></div>' +
      (block.english_preview
        ? '<div><dt>English preview</dt><dd><strong class="nlp-en-preview">' +
          escapeHtml(block.english_preview) +
          '</strong></dd></div>'
        : '') +
      '</dl>' +
      '</div>'
    );
  }

  function typeBadge(termType) {
    const t = String(termType || 'condition').toLowerCase();
    return (
      '<span class="nlp-type-badge nlp-type-badge--' +
      escapeHtml(t) +
      '">' +
      escapeHtml(t) +
      '</span>'
    );
  }

  function languageLabel(code) {
    const map = {
      hiligaynon: 'Hiligaynon',
      hiligaynon_mixed: 'Hiligaynon / mixed',
      ilonggo: 'Ilonggo',
      english: 'English',
    };
    return map[code] || escapeHtml(code || 'unknown');
  }

  function renderKeywords(keywords) {
    if (!keywords || !keywords.length) {
      return '<p class="nlp-empty">No keywords extracted.</p>';
    }
    const chips = keywords
      .map(function (kw) {
        const cls = kw.was_translated ? 'nlp-keyword-chip nlp-keyword-chip--translated' : 'nlp-keyword-chip';
        const arrow =
          kw.was_translated && kw.local_term !== kw.english_term
            ? escapeHtml(kw.local_term) + ' → <strong>' + escapeHtml(kw.english_term) + '</strong>'
            : '<strong>' + escapeHtml(kw.english_term || kw.local_term) + '</strong>';
        return '<li class="' + cls + '">' + arrow + '</li>';
      })
      .join('');
    return '<ul class="nlp-keyword-list">' + chips + '</ul>';
  }

  function renderFieldRecognition(title, recognition) {
    if (!recognition || !(String(recognition.original_input || '').trim())) {
      return '';
    }

    return (
      '<div class="nlp-recognition-panel">' +
      '<h4 class="nlp-subheading">' +
      escapeHtml(title) +
      '</h4>' +
      '<span class="nlp-lang-badge">' +
      languageLabel(recognition.detected_language) +
      '</span>' +
      '<p class="nlp-section-desc"><span class="nlp-label-inline">Original:</span></p>' +
      '<p class="nlp-original-text">' +
      escapeHtml(recognition.original_input) +
      '</p>' +
      '<p class="nlp-section-desc"><span class="nlp-label-inline">Translated English</span> (valid terms highlighted):</p>' +
      '<p class="nlp-translated-text">' +
      (recognition.highlighted_english || escapeHtml(recognition.translated_english || '—')) +
      '</p>' +
      '<p class="nlp-stats-row">' +
      '<span class="nlp-stat-chip nlp-stat-chip--valid">Valid: ' +
      escapeHtml(String(recognition.valid_count || 0)) +
      '</span>' +
      '<span class="nlp-stat-chip nlp-stat-chip--invalid">Not in dataset: ' +
      escapeHtml(String(recognition.invalid_count || 0)) +
      '</span>' +
      '</p>' +
      '<p class="nlp-section-desc">Detected keywords:</p>' +
      renderKeywords(recognition.detected_keywords || []) +
      '</div>'
    );
  }

  function renderMatchedRecords(records) {
    if (!records || !records.length) {
      return '<p class="nlp-empty">No verified dataset records.</p>';
    }
    const rows = records
      .map(function (r) {
        return (
          '<tr class="nlp-row--valid">' +
          '<td>' +
          typeBadge(r.term_type) +
          '</td>' +
          '<td><strong>' +
          escapeHtml(r.standardized_term || '') +
          '</strong> <span class="nlp-id">#' +
          escapeHtml(String(r.record_id || '')) +
          '</span></td>' +
          '<td><code class="nlp-source">' +
          escapeHtml(r.dataset_table || '') +
          '</code></td>' +
          '<td>' +
          escapeHtml(r.dataset_category || r.related_body_system || '—') +
          '</td>' +
          '<td>' +
          statusBadge('valid') +
          '</td></tr>'
        );
      })
      .join('');

    return (
      '<table class="nlp-table nlp-table--recognition">' +
      '<thead><tr><th>Type</th><th>Official term</th><th>Dataset</th><th>Category</th><th>Status</th></tr></thead>' +
      '<tbody>' +
      rows +
      '</tbody></table>'
    );
  }

  function renderTermWorkflow(termResults) {
    if (!termResults || !termResults.length) {
      return '<p class="nlp-empty">Enter Hiligaynon or English terms to validate.</p>';
    }

    const rows = termResults
      .map(function (t) {
        const valid = t.display_status === 'valid' || t.highlight === true;
        const rowClass = valid ? 'nlp-row--valid' : 'nlp-row--invalid';
        const lang =
          t.input_language === 'hiligaynon'
            ? 'Hiligaynon'
            : t.input_language === 'english'
              ? 'English'
              : escapeHtml(t.input_language || '—');
        const enCell =
          t.was_translated && t.original_local !== t.english_term
            ? '<span class="nlp-muted">' +
              escapeHtml(t.original_local) +
              '</span> → <strong class="nlp-en-highlight">' +
              escapeHtml(t.english_term) +
              '</strong>'
            : '<strong class="nlp-en-highlight">' + escapeHtml(t.english_term) + '</strong>';
        const std =
          valid && t.standardized_term
            ? '<strong class="nlp-valid-term">' + escapeHtml(t.standardized_term) + '</strong>'
            : '<span class="nlp-muted">—</span>';

        return (
          '<tr class="' +
          rowClass +
          '">' +
          '<td>' +
          typeBadge(t.term_type || (t.field === 'allergies' ? 'allergy' : 'condition')) +
          '</td>' +
          '<td>' +
          lang +
          '</td>' +
          '<td>' +
          enCell +
          '</td>' +
          '<td>' +
          std +
          (t.dataset_record_id ? ' <span class="nlp-id">#' + escapeHtml(String(t.dataset_record_id)) + '</span>' : '') +
          '</td>' +
          '<td>' +
          (t.fuzzy_score ? escapeHtml(String(t.fuzzy_score)) + '%' : '—') +
          '</td>' +
          '<td>' +
          statusBadge(t.display_status) +
          '</td>' +
          '<td class="nlp-user-err">' +
          escapeHtml(t.user_message || '') +
          '</td></tr>'
        );
      })
      .join('');

    return (
      '<table class="nlp-table nlp-table--workflow">' +
      '<thead><tr>' +
      '<th>Term type</th><th>Input lang.</th><th>English (after dictionary)</th><th>Official dataset match</th><th>Score</th><th>Status</th><th>Message</th>' +
      '</tr></thead><tbody>' +
      rows +
      '</tbody></table>'
    );
  }

  function renderTranslationTable(fieldBlock) {
    if (!fieldBlock || !fieldBlock.items || !fieldBlock.items.length) {
      return '<p class="nlp-empty">No keywords extracted for translation.</p>';
    }

    const rows = fieldBlock.items
      .map(function (item) {
        const cat = item.category || fieldBlock.expected_category || '—';
        const aiTag =
          item.source === 'ai_interpreter'
            ? ' <span class="nlp-ai-tag" title="AI-detected — still requires Steps 3–6 validation">AI</span>'
            : '';
        return (
          '<tr' +
          (item.source === 'ai_interpreter' ? ' class="nlp-row--ai"' : '') +
          '>' +
          '<td><code>' +
          escapeHtml(item.local_term) +
          '</code></td>' +
          '<td><strong>' +
          escapeHtml(item.english_term) +
          '</strong>' +
          aiTag +
          (item.ai_confidence ? ' <span class="nlp-ai-conf">' + escapeHtml(String(item.ai_confidence)) + '%</span>' : '') +
          '</td>' +
          '<td>' +
          escapeHtml(cat) +
          (item.category_match === false && item.status === 'matched'
            ? ' <span class="nlp-cat-warn" title="Dictionary category differs from field">≠</span>'
            : '') +
          '</td>' +
          '<td>' +
          statusBadge(item.status) +
          '</td>' +
          '<td>' +
          (item.ready_for_validation ? 'queued' : '—') +
          '</td>' +
          '</tr>'
        );
      })
      .join('');

    let fieldAi = '';
    const fieldAiBlock = fieldBlock.ai_interpretation;
    if (fieldAiBlock && fieldAiBlock.english_interpretation) {
      fieldAi =
        '<p class="nlp-ai-field-summary"><span class="nlp-label-inline">AI field interpretation:</span> ' +
        escapeHtml(fieldAiBlock.english_interpretation) +
        '</p>';
    }

    return (
      '<div class="nlp-trans-field">' +
      '<div class="nlp-trans-field-head">' +
      '<p class="nlp-trans-english"><span class="nlp-label-inline">English output:</span> ' +
      escapeHtml(fieldBlock.english_text || '—') +
      '</p>' +
      fieldAi +
      '<p class="nlp-trans-status">' +
      statusBadge(fieldBlock.status) +
      ' <span class="nlp-trans-status-label">' +
      escapeHtml(fieldBlock.status_label || '') +
      '</span></p>' +
      '</div>' +
      '<table class="nlp-table">' +
      '<thead><tr>' +
      '<th>Local term</th><th>English</th><th>Category</th><th>Translation</th><th>Next stage</th>' +
      '</tr></thead>' +
      '<tbody>' +
      rows +
      '</tbody></table>' +
      '</div>'
    );
  }

  function renderTranslationPipeline(pipeline, fieldLabel) {
    if (!pipeline || typeof pipeline !== 'object') {
      return '';
    }

    const sequence = pipeline.sequence || [];
    const stages = pipeline.stages || pipeline[fieldLabel] || pipeline.conditions || {};
    const labels = pipeline.labels || {};

    if (!sequence.length && !Object.keys(stages).length) {
      return '';
    }

    const stageKeys = sequence.length
      ? sequence.filter(function (key) {
          return key !== 'fuzzy_matching' && key !== 'validation';
        })
      : Object.keys(stages);

    let flowHtml = '';
    stageKeys.forEach(function (key, idx) {
      const stage = stages[key] || {};
      const label = stage.label || labels[key] || key.replace(/_/g, ' ');
      const status = String(stage.status || 'pending');
      const statusClass =
        status === 'complete' ? 'nlp-pipe-stage--ok' : status === 'empty' || status === 'none' ? 'nlp-pipe-stage--muted' : 'nlp-pipe-stage--warn';

      let detail = '';
      if (key === 'patient_input' && stage.text) {
        detail = '<span class="nlp-pipe-detail">' + escapeHtml(stage.text) + '</span>';
      } else if (key === 'medical_dictionary' && stage.match_count != null) {
        detail = '<span class="nlp-pipe-detail">' + escapeHtml(String(stage.match_count)) + ' match(es)</span>';
      } else if (key === 'hiligaynon_dataset' && stage.match_count != null) {
        detail = '<span class="nlp-pipe-detail">' + escapeHtml(String(stage.match_count)) + ' match(es)</span>';
      } else if (key === 'keyword_extraction' && stage.keywords && stage.keywords.length) {
        detail =
          '<span class="nlp-pipe-detail"><code>' + escapeHtml(stage.keywords.join(', ')) + '</code></span>';
      } else if (key === 'groq_context_analysis') {
        const provider = stage.provider ? String(stage.provider) : 'groq';
        const model = stage.model ? String(stage.model) : '—';
        detail =
          '<span class="nlp-pipe-detail"><code>' +
          escapeHtml(provider) +
          '</code> · ' +
          escapeHtml(model) +
          (stage.confidence_score != null ? ' · ' + escapeHtml(String(stage.confidence_score)) + '%' : '') +
          '</span>';
      } else if (key === 'english_interpretation' && stage.english_text) {
        detail = '<span class="nlp-pipe-detail">' + escapeHtml(stage.english_text) + '</span>';
      }

      flowHtml +=
        '<li class="nlp-pipe-stage ' +
        statusClass +
        '">' +
        '<span class="nlp-pipe-stage-num">' +
        String(idx + 1) +
        '</span>' +
        '<div class="nlp-pipe-stage-body">' +
        '<strong class="nlp-pipe-stage-label">' +
        escapeHtml(label) +
        '</strong>' +
        detail +
        '</div>' +
        '</li>';

      if (idx < stageKeys.length - 1) {
        flowHtml += '<li class="nlp-pipe-arrow" aria-hidden="true">→</li>';
      }
    });

    const downstream =
      '<li class="nlp-pipe-arrow nlp-pipe-arrow--downstream" aria-hidden="true">→</li>' +
      '<li class="nlp-pipe-stage nlp-pipe-stage--downstream"><span class="nlp-pipe-stage-num">7</span>' +
      '<div class="nlp-pipe-stage-body"><strong class="nlp-pipe-stage-label">Fuzzy Matching</strong>' +
      '<span class="nlp-pipe-detail">Step 3 · RapidFuzz ≥85%</span></div></li>' +
      '<li class="nlp-pipe-arrow nlp-pipe-arrow--downstream" aria-hidden="true">→</li>' +
      '<li class="nlp-pipe-stage nlp-pipe-stage--downstream"><span class="nlp-pipe-stage-num">8</span>' +
      '<div class="nlp-pipe-stage-body"><strong class="nlp-pipe-stage-label">Validation</strong>' +
      '<span class="nlp-pipe-detail">Step 4 · Official datasets</span></div></li>';

    return (
      '<div class="nlp-pipeline-flow">' +
      '<h4 class="nlp-subheading">Translation pipeline</h4>' +
      '<p class="nlp-pipeline-desc">Patient Input → Medical Dictionary → Hiligaynon Dataset → Keyword Extraction → Groq Context Analysis → English Interpretation → Fuzzy Matching → Validation</p>' +
      '<ol class="nlp-pipeline-stages">' +
      flowHtml +
      downstream +
      '</ol>' +
      '</div>'
    );
  }

  function renderAiInterpretationPanel(aiBlock) {
    if (!aiBlock || typeof aiBlock !== 'object') {
      return (
        '<div class="nlp-ai-panel nlp-ai-panel--muted">' +
        '<p class="nlp-muted">AI language understanding layer not available (dictionary translation only).</p>' +
        '</div>'
      );
    }

    const status = String(aiBlock.status || 'unavailable');
    const provider = aiBlock.provider ? String(aiBlock.provider) : 'none';
    const model = aiBlock.model ? String(aiBlock.model) : '—';
    const groqError = aiBlock.groq_error ? String(aiBlock.groq_error) : '';
    const isGroqSuccess = status === 'complete' && provider === 'groq';
    const isFallback = status === 'fallback' || provider === 'dictionary_fallback';
    const confidence = aiBlock.overall_confidence != null ? String(aiBlock.overall_confidence) + '%' : '—';
    const interpretation = aiBlock.english_interpretation || '—';
    const concepts = aiBlock.detected_concepts || [];
    const queued = aiBlock.concepts_queued_for_validation != null ? String(aiBlock.concepts_queued_for_validation) : '0';

    let conceptRows = '';
    if (concepts.length) {
      conceptRows = concepts
        .map(function (c) {
          return (
            '<tr>' +
            '<td><strong>' +
            escapeHtml(c.term || '—') +
            '</strong></td>' +
            '<td>' +
            escapeHtml(c.type || '—') +
            '</td>' +
            '<td>' +
            escapeHtml(c.body_part || '—') +
            '</td>' +
            '<td>' +
            escapeHtml(c.severity || '—') +
            '</td>' +
            '<td>' +
            escapeHtml(c.duration || '—') +
            '</td>' +
            '<td>' +
            escapeHtml(String(c.confidence != null ? c.confidence : '—')) +
            '%</td>' +
            '</tr>'
          );
        })
        .join('');
    } else {
      conceptRows = '<tr><td colspan="6" class="nlp-muted">No additional AI concepts extracted.</td></tr>';
    }

    const statusClass = isGroqSuccess
      ? 'nlp-ai-panel--ok'
      : isFallback
        ? 'nlp-ai-panel--warn'
        : status === 'disabled'
          ? 'nlp-ai-panel--muted'
          : 'nlp-ai-panel--warn';

    const statusLabel = isGroqSuccess
      ? 'Groq AI translation active'
      : isFallback
        ? 'Dictionary fallback (Groq unavailable)'
        : status;

    const symptoms = (concepts || []).filter(function (c) {
      return c.type === 'symptom';
    });
    const conditions = (concepts || []).filter(function (c) {
      return c.type === 'condition';
    });
    const allergies = (concepts || []).filter(function (c) {
      return c.type === 'allergy';
    });
    const bodyParts = (concepts || []).filter(function (c) {
      return c.type === 'body_part';
    });

    function entityList(items) {
      if (!items.length) return '<span class="nlp-muted">—</span>';
      return items.map(function (c) {
        return '<code>' + escapeHtml(c.term || '—') + '</code>';
      }).join(', ');
    }

    return (
      '<div class="nlp-ai-panel ' +
      statusClass +
      '">' +
      '<h4 class="nlp-subheading">Groq contextual language understanding</h4>' +
      (isGroqSuccess
        ? '<p class="nlp-ai-policy nlp-status-line--ok">✓ Groq Connected · Provider: <code>groq</code> · Model: <code>' +
          escapeHtml(model) +
          '</code> · AI Translation Active</p>'
        : isFallback && groqError
          ? '<p class="nlp-ai-policy nlp-status-line--warn">⚠ Groq Failed<br>Reason: <strong>' +
            escapeHtml(groqError) +
            '</strong><br>Using Dictionary Fallback</p>'
          : '<p class="nlp-ai-policy">' +
            escapeHtml(aiBlock.policy || 'AI improves translation only. All concepts must pass fuzzy matching and dataset validation.') +
            '</p>') +
      '<dl class="nlp-ai-meta">' +
      '<div><dt>Translation status</dt><dd>' +
      statusBadge(isGroqSuccess || isFallback ? 'complete' : status === 'disabled' ? 'empty' : 'partial') +
      ' ' +
      escapeHtml(statusLabel) +
      '</dd></div>' +
      '<div><dt>Active provider</dt><dd><code>' +
      escapeHtml(isGroqSuccess ? 'groq' : provider) +
      '</code></dd></div>' +
      '<div><dt>Model used</dt><dd><code>' +
      escapeHtml(isGroqSuccess ? model : isFallback ? '—' : model) +
      '</code></dd></div>' +
      '<div><dt>AI confidence</dt><dd><strong>' +
      escapeHtml(confidence) +
      '</strong></dd></div>' +
      '<div><dt>Concepts queued</dt><dd>' +
      escapeHtml(queued) +
      ' → Step 3 fuzzy matching</dd></div>' +
      '</dl>' +
      '<p class="nlp-ai-interpretation"><span class="nlp-label-inline">Groq medical interpretation:</span> ' +
      escapeHtml(interpretation) +
      '</p>' +
      '<div class="nlp-entity-grid">' +
      '<div><span class="nlp-label-inline">Detected symptoms</span> ' + entityList(symptoms) + '</div>' +
      '<div><span class="nlp-label-inline">Detected conditions</span> ' + entityList(conditions) + '</div>' +
      '<div><span class="nlp-label-inline">Detected allergies</span> ' + entityList(allergies) + '</div>' +
      '<div><span class="nlp-label-inline">Body parts</span> ' + entityList(bodyParts) + '</div>' +
      '</div>' +
      (aiBlock.conditions && aiBlock.conditions.notes
        ? '<p class="nlp-muted">' + escapeHtml(aiBlock.conditions.notes) + '</p>'
        : '') +
      '<h5 class="nlp-ai-concepts-title">Detected medical concepts</h5>' +
      '<table class="nlp-table nlp-table--ai-concepts">' +
      '<thead><tr><th>Term</th><th>Type</th><th>Body part</th><th>Severity</th><th>Duration</th><th>Confidence</th></tr></thead>' +
      '<tbody>' +
      conceptRows +
      '</tbody></table>' +
      '</div>'
    );
  }

  function renderDiagnosticsPanel(diag) {
    if (!diag || typeof diag !== 'object') return '';
    const warnings = diag.warnings || [];
    let warnHtml = '';
    if (warnings.length) {
      warnHtml =
        '<ul class="nlp-warn-list">' +
        warnings.map(function (w) {
          return '<li>' + escapeHtml(w) + '</li>';
        }).join('') +
        '</ul>';
    }

    const stages = (diag.keyword_extraction && diag.keyword_extraction.conditions_keywords) || [];
    const dictMatches =
      (diag.pipeline && diag.pipeline.conditions && diag.pipeline.conditions.medical_dictionary && diag.pipeline.conditions.medical_dictionary.matches) ||
      [];

    return (
      '<div class="nlp-diagnostics-panel">' +
      '<h4 class="nlp-subheading">Pipeline diagnostics</h4>' +
      warnHtml +
      '<dl class="nlp-diag-meta">' +
      '<div><dt>Dictionary</dt><dd>' +
      (diag.dictionary && diag.dictionary.loaded ? 'loaded (' + diag.dictionary.term_count + ' terms)' : 'not loaded') +
      '</dd></div>' +
      '<div><dt>Hiligaynon dataset</dt><dd>' +
      (diag.hiligaynon_dataset && diag.hiligaynon_dataset.loaded
        ? 'loaded (' +
          diag.hiligaynon_dataset.row_count +
          ' rows' +
          (diag.hiligaynon_dataset.variant_count
            ? ', ' + diag.hiligaynon_dataset.variant_count + ' variants'
            : '') +
          (diag.hiligaynon_dataset.wv_symptoms ? ', WV expansion active' : '') +
          ')'
        : 'not loaded') +
      '</dd></div>' +
      '<div><dt>Keywords extracted</dt><dd><code>' +
      escapeHtml(stages.join(', ') || '—') +
      '</code></dd></div>' +
      '<div><dt>Groq</dt><dd>' +
      (diag.groq && diag.groq.configured ? 'configured · ' : 'missing key · ') +
      escapeHtml((diag.groq && diag.groq.status) || 'unknown') +
      (diag.groq && diag.groq.called ? ' (called)' : '') +
      '</dd></div>' +
      '<div><dt>Python service</dt><dd>' +
      (diag.python_service && diag.python_service.used ? 'used' : 'PHP fallback') +
      '</dd></div>' +
      '</dl></div>'
    );
  }

  function renderStep2StageDetail(pipeline, prep) {
    if (!pipeline || !pipeline.conditions) return '';
    const stages = pipeline.conditions;
    const dict = stages.medical_dictionary || {};
    const dataset = stages.hiligaynon_dataset || {};
    const kw = stages.keyword_extraction || {};
    const patient = stages.patient_input || {};

    function matchRows(matches) {
      if (!matches || !matches.length) return '<tr><td colspan="3" class="nlp-muted">No matches</td></tr>';
      return matches
        .map(function (m) {
          return (
            '<tr><td><code>' +
            escapeHtml(m.local_term || '—') +
            '</code></td><td>' +
            escapeHtml(m.english_term || '—') +
            '</td><td>' +
            escapeHtml(m.category || m.source || '—') +
            '</td></tr>'
          );
        })
        .join('');
    }

    return (
      '<div class="nlp-step2-detail">' +
      '<p><span class="nlp-label-inline">Original input:</span> <code>' +
      escapeHtml(patient.text || prep.original || '—') +
      '</code></p>' +
      '<h5 class="nlp-ai-concepts-title">Dictionary matches (' +
      String(dict.match_count || 0) +
      ')</h5>' +
      '<table class="nlp-table nlp-table--compact"><thead><tr><th>Local</th><th>English</th><th>Category</th></tr></thead><tbody>' +
      matchRows(dict.matches) +
      '</tbody></table>' +
      '<h5 class="nlp-ai-concepts-title">Dataset matches (' +
      String(dataset.match_count || 0) +
      ')</h5>' +
      '<table class="nlp-table nlp-table--compact"><thead><tr><th>Local</th><th>English</th><th>Category</th></tr></thead><tbody>' +
      matchRows(dataset.matches) +
      '</tbody></table>' +
      '<p><span class="nlp-label-inline">Extracted keywords:</span> <code>' +
      escapeHtml((kw.keywords || []).join(', ') || '—') +
      '</code></p>' +
      '</div>'
    );
  }

  function renderFuzzyTable(fieldBlock) {
    if (!fieldBlock || !fieldBlock.results || !fieldBlock.results.length) {
      return '<p class="nlp-empty">No terms queued for fuzzy matching.</p>';
    }

    const rows = fieldBlock.results
      .map(function (row) {
        const matched = row.matched_term
          ? escapeHtml(row.matched_term)
          : '<span class="nlp-muted">—</span>';
        const standardized =
          row.standardized_term && row.validation_status === 'accepted'
            ? escapeHtml(row.standardized_term)
            : '<span class="nlp-muted">—</span>';
        return (
          '<tr>' +
          '<td><code>' +
          escapeHtml(row.local_term || '—') +
          '</code></td>' +
          '<td>' +
          escapeHtml(row.english_term || row.input_term || '—') +
          '</td>' +
          '<td>' +
          matched +
          '</td>' +
          '<td><strong>' +
          escapeHtml(String(row.similarity_score ?? 0)) +
          '%</strong></td>' +
          '<td>' +
          confidenceBadge(row.confidence_level) +
          '</td>' +
          '<td>' +
          standardized +
          '</td>' +
          '<td>' +
          statusBadge(row.validation_status) +
          '</td>' +
          '</tr>'
        );
      })
      .join('');

    const threshold = fieldBlock.threshold || 85;

    return (
      '<div class="nlp-fuzzy-field">' +
      '<p class="nlp-trans-status">' +
      statusBadge(fieldBlock.status) +
      ' <span class="nlp-trans-status-label">' +
      escapeHtml(fieldBlock.status_label || '') +
      '</span></p>' +
      '<p class="nlp-threshold-note">Acceptance threshold: <strong>' +
      escapeHtml(String(threshold)) +
      '%</strong> (RapidFuzz WRatio)</p>' +
      '<table class="nlp-table">' +
      '<thead><tr>' +
      '<th>Local</th><th>Translated</th><th>Best match</th><th>Score</th><th>Confidence</th><th>Standardized</th><th>Status</th>' +
      '</tr></thead>' +
      '<tbody>' +
      rows +
      '</tbody></table>' +
      '</div>'
    );
  }

  function renderRecordCell(record) {
    if (!record) {
      return '<span class="nlp-muted">—</span>';
    }
    let html =
      '<strong>' +
      escapeHtml(record.name) +
      '</strong> <span class="nlp-id">#' +
      escapeHtml(String(record.record_id)) +
      '</span>';
    if (record.dataset_category) {
      html += '<br><span class="nlp-rec-cat">' + escapeHtml(record.dataset_category) + '</span>';
    }
    return html;
  }

  function renderFinalValidationTable(fieldBlock) {
    if (!fieldBlock || !fieldBlock.results || !fieldBlock.results.length) {
      return '<p class="nlp-empty">No terms reached dataset validation.</p>';
    }

    const sourceLabel =
      (fieldBlock.dataset_table || 'dataset') +
      ' · ' +
      (fieldBlock.dataset_source || '');

    const rows = fieldBlock.results
      .map(function (row) {
        const blocked = row.blocked ? 'Yes' : 'No';
        return (
          '<tr class="' +
          (row.final_status === 'valid' ? 'nlp-row--valid' : 'nlp-row--invalid') +
          '">' +
          '<td><code>' +
          escapeHtml(row.local_term || '—') +
          '</code></td>' +
          '<td>' +
          escapeHtml(row.standardized_term || row.english_term || '—') +
          '</td>' +
          '<td>' +
          renderRecordCell(row.matched_record || row.record) +
          '</td>' +
          '<td><code class="nlp-source">' +
          escapeHtml(sourceLabel) +
          '</code></td>' +
          '<td><span class="nlp-val-result">' +
          escapeHtml(row.validation_result || '—') +
          '</span><br><span class="nlp-val-msg">' +
          escapeHtml(row.validation_message || '') +
          '</span></td>' +
          '<td>' +
          escapeHtml(String(row.fuzzy_score ?? 0)) +
          '%</td>' +
          '<td>' +
          (row.blocked ? statusBadge('invalid') : statusBadge('valid')) +
          ' ' +
          statusBadge(row.final_status) +
          '</td>' +
          '<td>' +
          blocked +
          '</td>' +
          '</tr>'
        );
      })
      .join('');

    return (
      '<div class="nlp-val-field">' +
      '<p class="nlp-trans-status">' +
      statusBadge(fieldBlock.status) +
      ' <span class="nlp-trans-status-label">' +
      escapeHtml(fieldBlock.status_label || '') +
      '</span></p>' +
      '<table class="nlp-table nlp-table--validation">' +
      '<thead><tr>' +
      '<th>Local</th><th>Term</th><th>Matched record</th><th>Dataset source</th><th>Validation</th><th>Fuzzy</th><th>Final status</th><th>Blocked</th>' +
      '</tr></thead>' +
      '<tbody>' +
      rows +
      '</tbody></table>' +
      '</div>'
    );
  }

  function renderRegistrationGate(reg) {
    if (!reg) return '';

    const eligible = !!reg.eligible;
    let html =
      '<div class="nlp-registration ' +
      (eligible ? 'nlp-registration--ok' : 'nlp-registration--blocked') +
      '">' +
      '<h4 class="nlp-subheading">Registration gate</h4>' +
      '<p class="nlp-reg-label">' +
      statusBadge(eligible ? 'valid' : 'invalid') +
      ' <span>' +
      escapeHtml(reg.eligible_label || '') +
      '</span></p>';

    if (reg.conditions && reg.conditions.length) {
      html +=
        '<p class="nlp-reg-list"><span class="nlp-label-inline">Conditions to save:</span> ' +
        escapeHtml(
          reg.conditions
            .map(function (c) {
              return c.standardized_term + ' (#' + c.record_id + ')';
            })
            .join(', ')
        ) +
        '</p>';
    }
    if (reg.symptoms && reg.symptoms.length) {
      html +=
        '<p class="nlp-reg-list"><span class="nlp-label-inline">Symptoms to save:</span> ' +
        escapeHtml(
          reg.symptoms
            .map(function (c) {
              return c.standardized_term + ' (#' + c.record_id + ')';
            })
            .join(', ')
        ) +
        '</p>';
    }
    if (reg.allergies && reg.allergies.length) {
      html +=
        '<p class="nlp-reg-list"><span class="nlp-label-inline">Allergies to save:</span> ' +
        escapeHtml(
          reg.allergies
            .map(function (a) {
              return a.standardized_term + ' (#' + a.record_id + ')';
            })
            .join(', ')
        ) +
        '</p>';
    }
    if (reg.rejected && reg.rejected.length) {
      html += '<p class="nlp-reg-rejected"><span class="nlp-label-inline">Blocked:</span></p><ul>';
      reg.rejected.forEach(function (r) {
        html +=
          '<li><code>' +
          escapeHtml(r.local_term || r.english_term || '—') +
          '</code> — ' +
          escapeHtml(r.validation_message || r.validation_result || 'invalid') +
          '</li>';
      });
      html += '</ul>';
    }
    html += '</div>';

    return html;
  }

  function renderConfidenceAssessment(block) {
    if (!block || !block.items) {
      return '<p class="nlp-empty">No validated terms for confidence scoring.</p>';
    }

    let html =
      '<p class="nlp-section-desc">Confidence levels: 95–100% Very High · 90–94% High · 85–89% Moderate · Below 85% Rejected</p>';

    if (block.overall_score_display && block.overall_score_display !== '—') {
      html +=
        '<p class="nlp-overall-status">Overall confidence: <strong>' +
        escapeHtml(block.overall_score_display) +
        '</strong> ' +
        statusBadge(block.overall_level || 'unknown') +
        '</p>';
    }

    html += '<ul class="nlp-confidence-list">';
    block.items.forEach(function (item) {
      html +=
        '<li class="nlp-confidence-item ' +
        (item.accepted ? 'nlp-confidence-item--ok' : 'nlp-confidence-item--bad') +
        '">' +
        '<span class="nlp-confidence-term">' +
        escapeHtml(item.term) +
        '</span>' +
        '<span class="nlp-confidence-score">Confidence: <strong>' +
        escapeHtml(item.confidence_display || '—') +
        '</strong></span>' +
        '<span class="nlp-confidence-label">' +
        escapeHtml(item.confidence_label || '') +
        '</span>' +
        '</li>';
    });
    html += '</ul>';

    return html;
  }

  function renderStepNav() {
    const steps = [
      { id: 'nlp-step-1', label: '1 Preprocess' },
      { id: 'nlp-step-2', label: '2 Translate' },
      { id: 'nlp-step-3', label: '3 Fuzzy' },
      { id: 'nlp-step-4', label: '4 Validate' },
      { id: 'nlp-step-5', label: '5 Confidence' },
      { id: 'nlp-step-6', label: '6 Urgency' },
      { id: 'nlp-step-7', label: '7 Decision' },
    ];
    let html = '<nav class="nlp-step-nav" aria-label="Pipeline steps"><ul class="nlp-step-nav__list">';
    steps.forEach(function (step) {
      html +=
        '<li><a class="nlp-step-nav__link" href="#' +
        step.id +
        '">' +
        escapeHtml(step.label) +
        '</a></li>';
    });
    html += '</ul></nav>';
    return html;
  }

  function renderStepSummaryChips() {
    const steps = [
      { id: 'nlp-step-1', label: 'Step 1' },
      { id: 'nlp-step-2', label: 'Step 2' },
      { id: 'nlp-step-3', label: 'Step 3' },
      { id: 'nlp-step-4', label: 'Step 4' },
      { id: 'nlp-step-5', label: 'Step 5' },
      { id: 'nlp-step-6', label: 'Step 6', active: true },
      { id: 'nlp-step-7', label: 'Step 7' },
    ];
    let html = '<ul class="nlp-step-summary">';
    steps.forEach(function (step) {
      html +=
        '<li class="nlp-step-summary__chip' +
        (step.active ? ' nlp-step-summary__chip--active' : '') +
        '"><a href="#' +
        step.id +
        '">' +
        escapeHtml(step.label) +
        '</a></li>';
    });
    html += '</ul>';
    return html;
  }

  function renderStep6Callout(block) {
    const data = block && typeof block === 'object' ? block : {};
    const urgency = data.triage_display || 'NON-URGENT';
    const icon = data.triage_icon || '';
    return (
      '<div class="nlp-step6-callout" id="nlp-step6-preview">' +
      '<p class="nlp-step6-callout__title">Step 6 — Clinical Urgency Classification</p>' +
      '<p class="nlp-step6-callout__value">' +
      escapeHtml(urgency) +
      ' ' +
      escapeHtml(icon) +
      '</p>' +
      '<p class="nlp-muted">' +
      escapeHtml(data.recommendation || data.recommended_action || 'Routine consultation') +
      '</p>' +
      '<p class="nlp-muted nlp-triage-reason">' +
      escapeHtml(data.clinical_reasoning || data.reason || 'Routine symptom profile.') +
      ' · <a href="#nlp-step-6">Full Step 6 details</a></p>' +
      '</div>'
    );
  }

  function renderClassificationLevelsReference() {
    return (
      '<div class="nlp-classification-levels">' +
      '<h4 class="nlp-classification-levels__title">Classification levels</h4>' +
      '<div class="nlp-classification-levels__grid">' +
      '<div class="nlp-classification-level"><strong>NON-URGENT</strong><br>Priority: Low<br>Recommendation: Routine consultation</div>' +
      '<div class="nlp-classification-level"><strong>URGENT</strong><br>Priority: Medium<br>Recommendation: Consult provider within hours</div>' +
      '<div class="nlp-classification-level"><strong>EMERGENCY</strong><br>Priority: Critical<br>Recommendation: Immediate emergency care</div>' +
      '</div></div>'
    );
  }

  function renderUrgencyExamples(examples) {
    if (!examples || typeof examples !== 'object') return '';
    const levels = [
      { key: 'NON-URGENT', className: 'nlp-urgency-card--routine', priority: 'Low' },
      { key: 'URGENT', className: 'nlp-urgency-card--urgent', priority: 'Medium' },
      { key: 'EMERGENCY', className: 'nlp-urgency-card--emergency', priority: 'Critical' },
    ];
    let html = '<div class="nlp-urgency-examples">';
    levels.forEach(function (level) {
      const items = examples[level.key] || [];
      html +=
        '<div class="nlp-urgency-card ' +
        level.className +
        '">' +
        '<h5 class="nlp-urgency-card__title">' +
        escapeHtml(level.key) +
        '</h5>' +
        '<p class="nlp-urgency-card__meta">Priority: ' +
        escapeHtml(level.priority) +
        '</p>' +
        '<ul class="nlp-urgency-card__list">';
      items.forEach(function (item) {
        html += '<li>' + escapeHtml(item) + '</li>';
      });
      html += '</ul></div>';
    });
    html += '</div>';
    return html;
  }

  function renderClinicalUrgency(block) {
    const data = block && typeof block === 'object' ? block : {};
    const urgency = data.triage_display || 'NON-URGENT';
    const urgencyClass =
      urgency === 'EMERGENCY'
        ? 'nlp-triage--emergency'
        : urgency === 'URGENT'
          ? 'nlp-triage--urgent'
          : 'nlp-triage--routine';

    function listSection(title, items) {
      if (!items || !items.length) {
        return '<p class="nlp-muted">' + escapeHtml(title) + ': None</p>';
      }
      let h = '<p class="nlp-label-inline">' + escapeHtml(title) + ':</p><ul class="nlp-symptom-list">';
      items.forEach(function (s) {
        h += '<li>' + escapeHtml(s) + '</li>';
      });
      h += '</ul>';
      return h;
    }

    const factors = data.assessment_factors || {};
    const factorRows = [
      ['Primary symptom', factors.primary_symptom],
      ['Symptom severity', factors.symptom_severity],
      ['Duration', factors.symptom_duration || '—'],
      ['Symptom count', factors.symptom_count],
      ['Body system', factors.body_system],
      ['Bleeding status', factors.bleeding_status],
      ['Breathing status', factors.breathing_status],
      ['Consciousness', factors.consciousness_status],
      ['Pain intensity', factors.pain_intensity],
    ];

    let html =
      renderClassificationLevelsReference() +
      '<div class="nlp-triage-panel ' +
      urgencyClass +
      '">' +
      '<h4 class="nlp-subheading">Triage result</h4>' +
      '<p><span class="nlp-label-inline">Final triage:</span> <strong class="nlp-triage-urgency-label">' +
      escapeHtml(urgency) +
      '</strong> <span class="nlp-triage-icon">' +
      escapeHtml(data.triage_icon || '') +
      '</span></p>' +
      '<p><span class="nlp-label-inline">Priority:</span> ' +
      escapeHtml(data.priority || 'Low') +
      '</p>' +
      '<p><span class="nlp-label-inline">Recommendation:</span> ' +
      escapeHtml(data.recommendation || data.recommended_action || 'Routine consultation') +
      '</p>';

    html += listSection('Detected symptoms', data.detected_symptoms);
    html += listSection('Detected conditions', data.detected_conditions);
    html += listSection('Body parts', data.detected_body_parts);

    html +=
      '<p><span class="nlp-label-inline">Severity score:</span> <strong>' +
      escapeHtml(String(data.severity_score != null ? data.severity_score : '—')) +
      '</strong> · <span class="nlp-label-inline">Severity:</span> ' +
      escapeHtml(data.severity || 'mild') +
      '</p>' +
      '<p><span class="nlp-label-inline">Clinical confidence:</span> <strong>' +
      escapeHtml(data.confidence_display || '—') +
      '</strong>' +
      (data.confidence_level_label ? ' (' + escapeHtml(data.confidence_level_label) + ')' : '') +
      '</p>';

    html += '<p class="nlp-label-inline">Emergency flags:</p>';
    if (data.emergency_flags && data.emergency_flags.length) {
      html += '<ul class="nlp-flag-list">';
      data.emergency_flags.forEach(function (f) {
        html += '<li class="nlp-flag-item nlp-flag-item--alert">' + escapeHtml(f) + '</li>';
      });
      html += '</ul>';
    } else {
      html += '<p class="nlp-muted">None</p>';
    }

    html +=
      '<div class="nlp-triage-reasoning">' +
      '<p class="nlp-label-inline">Clinical reasoning:</p>' +
      '<p class="nlp-triage-reason">' +
      escapeHtml(data.clinical_reasoning || data.reason || 'Routine symptom profile.') +
      '</p></div>';

    html += '<details class="nlp-triage-factors"><summary>Multi-factor assessment details</summary><dl class="nlp-diag-meta">';
    factorRows.forEach(function (row) {
      if (row[1] != null && row[1] !== '') {
        html += '<div><dt>' + escapeHtml(row[0]) + '</dt><dd>' + escapeHtml(String(row[1])) + '</dd></div>';
      }
    });
    if (factors.infection_indicators && factors.infection_indicators.length) {
      html += '<div><dt>Infection indicators</dt><dd>' + escapeHtml(factors.infection_indicators.join(', ')) + '</dd></div>';
    }
    if (factors.neurological_indicators && factors.neurological_indicators.length) {
      html += '<div><dt>Neurological indicators</dt><dd>' + escapeHtml(factors.neurological_indicators.join(', ')) + '</dd></div>';
    }
    if (factors.injury_mechanism) {
      html += '<div><dt>Injury mechanism</dt><dd>' + escapeHtml(factors.injury_mechanism) + '</dd></div>';
    }
    html += '<div><dt>Engine</dt><dd><code>' + escapeHtml(data.source || 'clinical_triage_engine_v2') + '</code></dd></div>';
    html += '</dl></details>';

    if (data.final_decision) {
      html +=
        '<p class="nlp-triage-final"><strong>Final decision:</strong> ' +
        escapeHtml(data.final_decision) +
        '</p>';
    }

    html += '</div>';
    html += renderUrgencyExamples(data.examples);
    return html;
  }

  function renderRegistrationDecision(block, invalidDet) {
    if (!block) return '<p class="nlp-empty">No registration decision.</p>';

    const status = block.final_status || 'REJECTED';
    const statusClass =
      status === 'ACCEPTED'
        ? 'nlp-registration--ok'
        : status === 'EMERGENCY PRIORITY'
          ? 'nlp-registration--emergency'
          : 'nlp-registration--blocked';

    let html =
      '<div class="nlp-registration ' +
      statusClass +
      '">' +
      '<p class="nlp-reg-label">' +
      statusBadge(status === 'REJECTED' ? 'invalid' : 'valid') +
      ' <strong>Final status: ' +
      escapeHtml(status) +
      '</strong></p>' +
      '<p>' +
      escapeHtml(block.message || '') +
      '</p>' +
      '<dl class="nlp-decision-dl">' +
      '<div><dt>save_allowed</dt><dd><code>' +
      escapeHtml(String(block.save_allowed === true)) +
      '</code></dd></div>';

    if (block.emergency_alert) {
      html +=
        '<div><dt>emergency_alert</dt><dd><code>true</code></dd></div>' +
        '<div><dt>priority_queue</dt><dd><code>' +
        escapeHtml(block.priority_queue || 'highest') +
        '</code></dd></div>' +
        '<div><dt>triage_level</dt><dd><code>EMERGENCY</code></dd></div>';
    }

    html += '</dl>';

    if (block.rules && block.rules.length) {
      html += '<h4 class="nlp-subheading">Decision rules</h4><ul class="nlp-rules-list">';
      block.rules.forEach(function (rule) {
        html +=
          '<li><strong>IF</strong> ' +
          escapeHtml(rule.condition) +
          ' <strong>THEN</strong> ' +
          escapeHtml(rule.result) +
          '</li>';
      });
      html += '</ul>';
    }

    if (invalidDet && invalidDet.invalid_entries && invalidDet.invalid_entries.length) {
      html += '<h4 class="nlp-subheading">Blocked terms</h4><ul class="nlp-blocked-list">';
      invalidDet.invalid_entries.forEach(function (entry) {
        html +=
          '<li><code>' +
          escapeHtml(entry.display_term || entry.local_term || '—') +
          '</code> — ' +
          escapeHtml(entry.user_friendly_error || entry.failure_reason || '') +
          '</li>';
      });
      html += '</ul>';
    }

    html += '</div>';
    return html;
  }

  function showResults(data) {
    resultsEl.hidden = false;

    const prep = data.preprocessing || {};
    const trans = data.translation || {};
    const fuzzy = data.fuzzy_matching || {};
    const validation = data.dataset_validation || {};
    const registration = data.registration || validation.registration || {};
    const confidence = data.confidence_assessment || {};
    const clinicalUrgency = data.clinical_urgency || {};
    const registrationDecision = data.registration_decision || {};
    const invalidDet = data.invalid_entry_detection || {};
    const dict = data.dictionary || {};
    const termResults = data.term_results || [];
    const workflow = data.workflow || {};

    let html = '';
    const summaryText = buildClientSummary(data);

    html += '<section class="nlp-section nlp-section--workflow">';
    html += '<h3>Validation summary</h3>';
    html +=
      '<p class="nlp-summary nlp-summary--lead"><span class="nlp-label-inline">Summary:</span> ' +
      escapeHtml(summaryText) +
      '</p>';
    if (workflow.policy) {
      html += '<p class="nlp-policy-note">' + escapeHtml(workflow.policy) + '</p>';
    }
    html += renderStep6Callout(clinicalUrgency);
    html += renderStepSummaryChips();
    html += '</section>';

    html += renderStepNav();

    html += '<section class="nlp-section nlp-section--prep" id="nlp-step-1">';
    html += '<h3>Step 1 — Preprocessing</h3>';
    html +=
      '<ul class="nlp-step-bullets">' +
      '<li>Convert text to lowercase</li>' +
      '<li>Remove punctuation and filler words</li>' +
      '<li>Normalize spelling variations and abbreviations</li>' +
      '<li>Extract potential medical keywords</li>' +
      '</ul>';
    html +=
      '<p class="nlp-section-desc">Display: Original Text → Normalized Text → Cleaned Text → Extracted Keywords → English Preview</p>';
    html +=
      '<p class="nlp-engine-inline">Engine: <code>Python AI Service</code> (preferred) · <code>PHP Fallback</code></p>';

    if (dict.loaded) {
      html +=
        '<p class="nlp-dict-meta">Dictionary loaded: <strong>' +
        escapeHtml(String(dict.loaded)) +
        '</strong> terms (' +
        escapeHtml(String(dict.conditions || 0)) +
        ' conditions, ' +
        escapeHtml(String(dict.allergies || 0)) +
        ' allergies)</p>';
    }

    html += renderPreprocessBlock('Existing Medical Conditions', prep.conditions);
    html += renderPreprocessBlock('Known Allergies', prep.allergies);
    html += '</section>';

    html += '<section class="nlp-section nlp-section--translate" id="nlp-step-2">';
    html += '<h3>Step 2 — Medical Translation</h3>';
    html +=
      '<ul class="nlp-step-bullets">' +
      '<li>Patient Input → Medical Dictionary → Hiligaynon Dataset → Keyword Extraction</li>' +
      '<li>Groq Context Analysis (Llama 3.3) → English Interpretation</li>' +
      '<li>All translated terms still pass Fuzzy Matching (Step 3) and Validation (Step 4)</li>' +
      '</ul>';
    html +=
      '<p class="nlp-section-desc">Display: Pipeline stages → Groq interpretation → dictionary translation → validation queue</p>';
    html += renderDiagnosticsPanel(data.pipeline_diagnostics);
    if (trans.pipeline) {
      html += renderTranslationPipeline(trans.pipeline, 'conditions');
      html += renderStep2StageDetail(trans.pipeline, prep.conditions || {});
    }
    html += renderAiInterpretationPanel(trans.ai_interpretation);
    html +=
      '<p class="nlp-overall-status">' +
      statusBadge(trans.overall_status) +
      ' <span class="nlp-trans-status-label">' +
      escapeHtml(trans.overall_status_label || '') +
      '</span></p>';
    if (trans.combined_english) {
      html +=
        '<p class="nlp-combined-en"><span class="nlp-label-inline">Combined English:</span> ' +
        escapeHtml(trans.combined_english) +
        '</p>';
    }
    html += '<h4 class="nlp-subheading">Medical conditions</h4>';
    html += renderTranslationTable(trans.conditions);
    html += '<h4 class="nlp-subheading">Allergies</h4>';
    html += renderTranslationTable(trans.allergies);
    html += '</section>';

    html += '<section class="nlp-section nlp-section--fuzzy" id="nlp-step-3">';
    html += '<h3>Step 3 — Fuzzy Matching</h3>';
    html +=
      '<p class="nlp-section-desc">English terms only · RapidFuzz WRatio · Minimum acceptance score: <strong>85%</strong></p>' +
      '<p class="nlp-section-desc">Match sources: <code>medical_conditions.csv</code>, <code>symptoms.csv</code>, <code>allergies.csv</code></p>';
    if (fuzzy.engine) {
      html +=
        '<p class="nlp-engine-inline">Matcher: <code>' + escapeHtml(fuzzy.engine) + '</code></p>';
    }
    html +=
      '<p class="nlp-overall-status">' +
      statusBadge(fuzzy.overall_status) +
      ' <span class="nlp-trans-status-label">' +
      escapeHtml(fuzzy.overall_status_label || '') +
      '</span></p>';
    html += '<h4 class="nlp-subheading">Medical conditions</h4>';
    html += renderFuzzyTable(fuzzy.conditions);
    html += '<h4 class="nlp-subheading">Allergies</h4>';
    html += renderFuzzyTable(fuzzy.allergies);
    html += '</section>';

    html += '<section class="nlp-section nlp-section--validate" id="nlp-step-4">';
    html += '<h3>Step 4 — Dataset Validation</h3>';
    html +=
      '<p class="nlp-section-desc">Verify matched terms exist in official datasets, link to record ID, reject unknown terms.</p>' +
      '<p class="nlp-section-desc">Display: Accepted Terms · Rejected Terms · Registration Gate Preview</p>';
    html +=
      '<p class="nlp-overall-status">' +
      statusBadge(validation.overall_status) +
      ' <span class="nlp-trans-status-label">' +
      escapeHtml(validation.overall_status_label || '') +
      '</span></p>';
    html += renderRegistrationGate(registration);
    html += '<h4 class="nlp-subheading">Medical conditions</h4>';
    html += renderFinalValidationTable(validation.conditions);
    html += '<h4 class="nlp-subheading">Symptoms</h4>';
    html += renderFinalValidationTable(validation.symptoms);
    html += '<h4 class="nlp-subheading">Allergies</h4>';
    html += renderFinalValidationTable(validation.allergies);
    html += '</section>';

    html += '<section class="nlp-section nlp-section--confidence" id="nlp-step-5">';
    html += '<h3>Step 5 — Medical Confidence Assessment</h3>';
    html +=
      '<p class="nlp-section-desc">For every recognized symptom or condition — confidence score and confidence level.</p>';
    html += renderConfidenceAssessment(confidence);
    html += '</section>';

    html += '<section class="nlp-section nlp-section--triage" id="nlp-step-6">';
    html += '<h3>Step 6 — Clinical Urgency Classification</h3>';
    html +=
      '<p class="nlp-section-desc">Only validated terms enter triage. Classification: <strong>NON-URGENT</strong>, <strong>URGENT</strong>, or <strong>EMERGENCY</strong>.</p>';
    html += renderClinicalUrgency(clinicalUrgency);
    html += '</section>';

    html += '<section class="nlp-section nlp-section--decision" id="nlp-step-7">';
    html += '<h3>Step 7 — Registration Decision</h3>';
    html += renderRegistrationDecision(registrationDecision, invalidDet);
    html += '</section>';

    html += '<section class="nlp-section nlp-section--recognition" id="nlp-appendix-recognition">';
    html += '<h3>Appendix — Medical term recognition</h3>';
    html += renderFieldRecognition('Known Medical Conditions & Symptoms', data.conditions_recognition || {});
    html += renderFieldRecognition('Known Allergies', data.allergies_recognition || {});
    html += '<h4 class="nlp-subheading">Matched dataset records (valid only)</h4>';
    html += renderMatchedRecords(data.matched_records || []);
    html += '<h4 class="nlp-subheading">All recognized terms</h4>';
    html += renderTermWorkflow(termResults);
    html += '</section>';

    const invalidDetLegacy = data.invalid_entry_detection || {};
    if (invalidDetLegacy.invalid_entries && invalidDetLegacy.invalid_entries.length) {
      html += '<section class="nlp-section nlp-section--invalid">';
      html += '<h3>Invalid entry details</h3>';
      html += '<table class="nlp-table nlp-table--invalid">';
      html += '<thead><tr>';
      html += '<th>Term</th><th>Type</th><th>Failure reason</th><th>Status</th><th>User message</th>';
      html += '</tr></thead><tbody>';
      invalidDetLegacy.invalid_entries.forEach(function (entry) {
        html +=
          '<tr class="nlp-row--invalid">' +
          '<td><code>' +
          escapeHtml(entry.display_term || entry.local_term || '—') +
          '</code></td>' +
          '<td>' +
          escapeHtml(entry.category || '—') +
          '</td>' +
          '<td><span class="nlp-val-result">' +
          escapeHtml(entry.failure_reason || '—') +
          '</span></td>' +
          '<td>' +
          statusBadge(entry.validation_status || 'invalid') +
          '</td>' +
          '<td class="nlp-user-err">' +
          escapeHtml(entry.user_friendly_error || '') +
          '</td></tr>';
      });
      html += '</tbody></table></section>';
    }

    if (data.save_allowed === false && data.submission_rejected) {
      html +=
        '<p class="nlp-save-blocked"><strong>Save blocked:</strong> Nothing will be written to the patient record until validation passes.</p>';
    }

    let engineNote = data.service_used
      ? ' (Python AI + Groq)'
      : ' (PHP fallback — not used when Python is required)';
    if (data.service_used) {
      const groq = (data.pipeline_diagnostics && data.pipeline_diagnostics.groq) || {};
      const groqStatus = groq.status || groq.provider || '';
      if (groqStatus) {
        engineNote += ' · Groq: ' + groqStatus;
      }
    } else if (data.service_used === false && data.service_online === false) {
      engineNote +=
        '. Start the AI service: <code>ai_service/.venv/Scripts/python.exe server.py</code> (port 8765)';
    } else if (data.service_used === false && data.service_online === true) {
      engineNote += '. Service is running but the analyze request failed or timed out';
    }

    html +=
      '<p class="nlp-engine">Engine: ' +
      escapeHtml(data.engine || 'unknown') +
      engineNote +
      '</p>';

    resultsEl.innerHTML = html;

    const step6 = document.getElementById('nlp-step-6');
    if (step6) {
      step6.classList.add('nlp-step-highlight');
      setTimeout(function () {
        step6.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 120);
    }
  }

  async function validate() {
    hideFeedback();
    hideResults();

    const conditions = (conditionsEl.value || '').trim();
    const allergies = (allergiesEl.value || '').trim();

    if (!conditions && !allergies) {
      showFeedback('Enter at least one field before validating.', 'error');
      return;
    }

    validateBtn.disabled = true;
    const prevLabel = validateBtn.textContent;
    validateBtn.textContent = 'Starting Python AI…';

    try {
      const serviceReady = await waitForPythonService(45000);
      if (!serviceReady.online) {
        showFeedback(
          'Python AI service with Groq is required but offline: ' +
            (serviceReady.reason || 'service not ready') +
            '. Run ai_service\\restart_ai_service.bat and ensure GROQ_API_KEY is in .env.',
          'error'
        );
        refreshServiceStatus();
        return;
      }

      validateBtn.textContent = 'Validating… (Python + Groq, usually under 60s)';

      const body = new FormData();
      body.append('existing_conditions', conditions);
      body.append('allergies', allergies);
      body.append('current_medications', conditions);

      const controller = new AbortController();
      const analyzeTimer = setTimeout(function () {
        controller.abort();
      }, 120000);

      const res = await fetch(apiUrl, { method: 'POST', body: body, signal: controller.signal });
      clearTimeout(analyzeTimer);
      const raw = await res.text();
      let json;
      try {
        json = JSON.parse(raw);
      } catch (parseErr) {
        showFeedback(
          'Server returned an invalid response (HTTP ' +
            res.status +
            '). Check the server error log or browser console for details.',
          'error'
        );
        console.error('API URL:', apiUrl, 'Response:', raw.slice(0, 500));
        return;
      }

      const d = json.data || {};
      const invalidDet =
        json.invalid_entry_detection || d.invalid_entry_detection || {};

      if (res.status === 503 || d.service_required) {
        showFeedback(
          json.message ||
            'Python AI service with Groq is required but was not used. Check GROQ_API_KEY and restart the AI service.',
          'error'
        );
        refreshServiceStatus();
        return;
      }

      if (d.engine === 'php-validation-workflow' || d.service_used === false) {
        showFeedback(
          'Analyze used PHP fallback instead of Python + Groq. Restart ai_service and try again.',
          'error'
        );
        if (d.preprocessing || d.translation || d.clinical_urgency) {
          showResults(d);
        }
        refreshServiceStatus();
        return;
      }

      const registrationDecision = d.registration_decision || {};
      const rejected =
        !!json.submission_rejected ||
        !!d.submission_rejected ||
        !!invalidDet.submission_rejected;

      if (
        d.preprocessing ||
        d.translation ||
        (d.term_results && d.term_results.length) ||
        d.summary ||
        d.confidence_assessment ||
        d.clinical_urgency
      ) {
        showResults(d);
      } else {
        showFeedback(
          'Validation finished but no detailed results were returned. Try again or check the server log.',
          'error'
        );
      }

      if (!json.success || rejected) {
        showFeedback(
          registrationDecision.message ||
            invalidDet.user_message ||
            json.message ||
            'Submission rejected — invalid medical entries detected.',
          rejected && registrationDecision.final_status === 'EMERGENCY PRIORITY' ? 'ok' : 'error'
        );
        return;
      }

      const label =
        registrationDecision.message ||
        invalidDet.user_message ||
        (d.registration || d.dataset_validation?.registration || {}).eligible_label ||
        json.message ||
        'Pipeline complete — see results below.';
      showFeedback(label, 'ok');
      refreshServiceStatus();
    } catch (err) {
      const detail = err && err.name === 'AbortError'
        ? 'Request timed out after 120s — Groq analyze may need more time on first run'
        : err && err.message
          ? err.message
          : String(err);
      showFeedback(
        'Validation request failed. XAMPP may be running — check the API path or server error log. (' + detail + ')',
        'error'
      );
      console.error(err);
    } finally {
      validateBtn.disabled = false;
      validateBtn.textContent = prevLabel;
    }
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    validate();
  });

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.nlp-wv-chip');
    if (!btn || !conditionsEl) return;
    const text = btn.getAttribute('data-text') || '';
    if (!text) return;
    conditionsEl.value = text;
    conditionsEl.focus();
    hideFeedback();
    btn.classList.add('nlp-wv-chip--selected');
    document.querySelectorAll('.nlp-wv-chip--selected').forEach(function (el) {
      if (el !== btn) el.classList.remove('nlp-wv-chip--selected');
    });
  });
})();
