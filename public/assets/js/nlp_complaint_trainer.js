(function () {
  'use strict';

  const base = window.APP_BASE || '';
  const apiUrl = base + '/app/api/ai/assess_chief_complaint.php';
  const LOG_KEY = 'medconnect_nlp_complaint_trainer_log';
  const ANALYZE_TIMEOUT_MS = 180000;

  const form = document.getElementById('nct-form');
  const textarea = document.getElementById('nct-complaint');
  const analyzeBtn = document.getElementById('nct-analyze');
  const clearBtn = document.getElementById('nct-clear');
  const debugEl = document.getElementById('nct-debug');
  const statusEl = document.getElementById('nct-status');
  const resultsEl = document.getElementById('nct-results');
  const resultsBody = document.getElementById('nct-results-body');
  const filterEl = document.getElementById('nct-filter');
  const logBody = document.querySelector('#nct-log tbody');
  const logEmpty = document.getElementById('nct-log-empty');
  const scoreEl = document.getElementById('nct-score');

  let expectedTriage = '';
  let analyzeController = null;
  let groupQueue = [];
  let groupRunning = false;

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function normalizeTriage(value) {
    const raw = String(value || '')
      .toUpperCase()
      .replace(/_/g, '-')
      .replace(/\s+/g, '-')
      .trim();
    if (raw === 'NON URGENT' || raw === 'NONURGENT' || raw === 'LOW' || raw === 'ROUTINE') {
      return 'NON-URGENT';
    }
    if (raw === 'HIGH') return 'URGENT';
    if (raw === 'CRITICAL') return 'EMERGENCY';
    if (raw === 'NON-URGENT' || raw === 'URGENT' || raw === 'EMERGENCY') return raw;
    return raw;
  }

  function triageClass(value) {
    const t = normalizeTriage(value);
    if (t === 'EMERGENCY') return 'emergency';
    if (t === 'URGENT') return 'urgent';
    if (t === 'NON-URGENT') return 'non-urgent';
    return 'muted';
  }

  function listify(value) {
    if (!value) return [];
    if (Array.isArray(value)) {
      return value
        .map(function (item) {
          if (item == null) return '';
          if (typeof item === 'string') return item;
          return item.label || item.name || item.term || item.symptom || item.text || JSON.stringify(item);
        })
        .map(function (s) {
          return String(s).trim();
        })
        .filter(Boolean);
    }
    if (typeof value === 'string') {
      return value
        .split(/[;,]/)
        .map(function (s) {
          return s.trim();
        })
        .filter(Boolean);
    }
    return [];
  }

  function showStatus(message, type) {
    statusEl.hidden = false;
    statusEl.className = 'nct-status nct-status--' + (type || 'ok');
    statusEl.textContent = message;
  }

  function loadLog() {
    try {
      const raw = localStorage.getItem(LOG_KEY);
      const parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch (_) {
      return [];
    }
  }

  function saveLog(rows) {
    try {
      localStorage.setItem(LOG_KEY, JSON.stringify(rows.slice(-80)));
    } catch (_) {
      /* ignore quota */
    }
  }

  function renderLog() {
    const rows = loadLog();
    logBody.innerHTML = rows
      .map(function (row, i) {
        const match = row.match;
        const matchHtml =
          row.expected === ''
            ? '<span class="nct-badge nct-badge--muted">n/a</span>'
            : '<span class="nct-badge nct-badge--' +
              (match ? 'ok' : 'bad') +
              '">' +
              (match ? 'match' : 'mismatch') +
              '</span>';
        return (
          '<tr>' +
          '<td>' +
          (i + 1) +
          '</td>' +
          '<td>' +
          escapeHtml(row.complaint) +
          '</td>' +
          '<td>' +
          escapeHtml(row.expected || '—') +
          '</td>' +
          '<td><span class="nct-badge nct-badge--' +
          triageClass(row.actual) +
          '">' +
          escapeHtml(row.actual || '—') +
          '</span></td>' +
          '<td>' +
          matchHtml +
          '</td>' +
          '<td>' +
          escapeHtml(row.confidence || '—') +
          '</td>' +
          '<td>' +
          escapeHtml(row.english || '—') +
          '</td>' +
          '</tr>'
        );
      })
      .join('');

    logEmpty.classList.toggle('is-hidden', rows.length > 0);

    const scored = rows.filter(function (r) {
      return r.expected;
    });
    const hits = scored.filter(function (r) {
      return r.match;
    }).length;
    if (!rows.length) {
      scoreEl.textContent = '0 tested';
    } else if (!scored.length) {
      scoreEl.textContent = rows.length + ' tested';
    } else {
      scoreEl.textContent =
        hits + '/' + scored.length + ' match · ' + rows.length + ' tested';
    }
  }

  function appendLog(entry) {
    const rows = loadLog();
    rows.push(entry);
    saveLog(rows);
    renderLog();
  }

  function pills(items, flag) {
    if (!items.length) return '<p class="nct-hint">None</p>';
    return (
      '<div class="nct-pills">' +
      items
        .map(function (item) {
          return (
            '<span class="nct-pill' +
            (flag ? ' nct-pill--flag' : '') +
            '">' +
            escapeHtml(item) +
            '</span>'
          );
        })
        .join('') +
      '</div>'
    );
  }

  function renderResult(payload, complaint) {
    const summary = payload.summary || {};
    const clinical = payload.clinical_urgency || {};
    const assessment = payload.assessment || {};
    const triageMeta = assessment.triage && typeof assessment.triage === 'object' ? assessment.triage : {};
    const actual = normalizeTriage(
      clinical.triage_display || summary.classification || triageMeta.triage_display
    );
    const expected = normalizeTriage(expectedTriage);
    const match = expected !== '' && actual === expected;
    const english =
      summary.english_translation ||
      assessment.english_translation ||
      '';
    const confidence =
      clinical.confidence_display ||
      (summary.confidence != null ? String(summary.confidence) + '%' : '') ||
      '';
    const symptoms = listify(
      summary.detected_symptoms || clinical.detected_symptoms || assessment.detected_symptoms
    );
    const conditions = listify(
      clinical.detected_conditions || assessment.possible_conditions
    );
    const flags = listify(summary.red_flags || clinical.emergency_flags);
    const reasoning =
      summary.clinical_reasoning ||
      clinical.clinical_reasoning ||
      summary.reason ||
      '';
    const language = summary.detected_language || assessment.detected_language || '';
    const engine = summary.engine || assessment.engine || payload.engine_chain || '';
    const action = clinical.recommended_action || summary.recommended_action || '';

    resultsEl.hidden = false;
    resultsBody.innerHTML =
      '<div class="nct-result-grid">' +
      '<div class="nct-stat"><dt>Triage</dt><dd><span class="nct-badge nct-badge--' +
      triageClass(actual) +
      '">' +
      escapeHtml(actual || '—') +
      '</span></dd></div>' +
      '<div class="nct-stat"><dt>Expected</dt><dd>' +
      (expected
        ? '<span class="nct-badge nct-badge--' +
          triageClass(expected) +
          '">' +
          escapeHtml(expected) +
          '</span>'
        : '—') +
      '</dd></div>' +
      '<div class="nct-stat"><dt>Match</dt><dd>' +
      (expected
        ? '<span class="nct-badge nct-badge--' +
          (match ? 'ok' : 'bad') +
          '">' +
          (match ? 'match' : 'mismatch') +
          '</span>'
        : '<span class="nct-badge nct-badge--muted">n/a</span>') +
      '</dd></div>' +
      '<div class="nct-stat"><dt>Confidence</dt><dd>' +
      escapeHtml(confidence || '—') +
      '</dd></div>' +
      '<div class="nct-stat"><dt>Language</dt><dd>' +
      escapeHtml(language || '—') +
      '</dd></div>' +
      '<div class="nct-stat"><dt>Engine</dt><dd>' +
      escapeHtml(engine || '—') +
      '</dd></div>' +
      '</div>' +
      '<div class="nct-block"><h3>Original</h3><p>' +
      escapeHtml(complaint) +
      '</p></div>' +
      '<div class="nct-block"><h3>English translation</h3><p>' +
      escapeHtml(english || '—') +
      '</p></div>' +
      '<div class="nct-block"><h3>Detected symptoms</h3>' +
      pills(symptoms) +
      '</div>' +
      '<div class="nct-block"><h3>Possible conditions</h3>' +
      pills(conditions) +
      '</div>' +
      '<div class="nct-block"><h3>Red flags</h3>' +
      pills(flags, true) +
      '</div>' +
      '<div class="nct-block"><h3>Clinical reasoning</h3><p>' +
      escapeHtml(reasoning || action || '—') +
      '</p></div>' +
      (payload.pipeline_debug
        ? '<div class="nct-block nct-debug"><h3>Pipeline debug</h3><pre>' +
          escapeHtml(JSON.stringify(payload.pipeline_debug, null, 2)) +
          '</pre></div>'
        : '');

    appendLog({
      complaint: complaint,
      expected: expected,
      actual: actual,
      match: match,
      confidence: confidence,
      english: english,
      at: new Date().toISOString(),
    });
  }

  async function analyze(text) {
    const complaint = String(text || '').trim();
    if (!complaint) {
      showStatus('Enter a complaint first.', 'warn');
      textarea.focus();
      return;
    }

    if (analyzeController) {
      analyzeController.abort();
    }
    analyzeController = new AbortController();
    analyzeBtn.disabled = true;
    analyzeBtn.textContent = 'Analyzing…';
    showStatus('Running chief-complaint NLP…', 'ok');

    const body = new FormData();
    body.append('chief_complaint', complaint);
    if (debugEl.checked) {
      body.append('debug', '1');
    }

    const timer = setTimeout(function () {
      if (analyzeController) analyzeController.abort();
    }, ANALYZE_TIMEOUT_MS);

    try {
      const res = await fetch(apiUrl, {
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
        showStatus('Could not parse NLP response.', 'bad');
        return;
      }

      const payload = json && (json.data || json);
      if (!res.ok || json.success === false) {
        if (payload && (payload.summary || payload.assessment || payload.clinical_urgency)) {
          renderResult(payload, complaint);
          showStatus(json.message || 'Analyzed with warnings.', 'warn');
          return;
        }
        showStatus(json.message || 'Unable to analyze complaint.', 'bad');
        return;
      }

      renderResult(payload, complaint);
      const actual = normalizeTriage(
        (payload.clinical_urgency && payload.clinical_urgency.triage_display) ||
          (payload.summary && payload.summary.classification)
      );
      const expected = normalizeTriage(expectedTriage);
      if (expected && actual && expected !== actual) {
        showStatus('Mismatch: expected ' + expected + ', got ' + actual + '.', 'warn');
      } else {
        showStatus(json.message || 'Analysis complete.', 'ok');
      }
    } catch (err) {
      clearTimeout(timer);
      if (err && err.name === 'AbortError') {
        showStatus('Analysis cancelled or timed out.', 'warn');
        return;
      }
      showStatus('Network error talking to NLP API.', 'bad');
    } finally {
      analyzeBtn.disabled = false;
      analyzeBtn.textContent = 'Analyze';
      analyzeController = null;
    }
  }

  function applyChip(chip, autoRun) {
    document.querySelectorAll('.nct-chip.is-active').forEach(function (el) {
      el.classList.remove('is-active');
    });
    chip.classList.add('is-active');
    textarea.value = chip.getAttribute('data-text') || '';
    expectedTriage = chip.getAttribute('data-expected') || '';
    if (autoRun !== false) {
      analyze(textarea.value);
    }
  }

  function setGroupFilter(group) {
    document.querySelectorAll('.nct-tab').forEach(function (tab) {
      tab.classList.toggle('is-active', tab.getAttribute('data-group') === group);
    });
    document.querySelectorAll('.nct-group').forEach(function (block) {
      const show = group === 'all' || block.getAttribute('data-group') === group;
      block.classList.toggle('is-hidden', !show);
    });
  }

  function filterChips() {
    const q = String(filterEl.value || '')
      .toLowerCase()
      .trim();
    document.querySelectorAll('.nct-chip').forEach(function (chip) {
      const text = (chip.getAttribute('data-text') || '').toLowerCase();
      chip.classList.toggle('is-filtered', q !== '' && text.indexOf(q) === -1);
    });
  }

  async function runGroup(group) {
    const chips = Array.prototype.slice.call(
      document.querySelectorAll('.nct-chip[data-group="' + group + '"]:not(.is-filtered)')
    );
    if (!chips.length || groupRunning) return;
    groupRunning = true;
    groupQueue = chips.slice();
    showStatus('Running ' + chips.length + ' dummy complaints…', 'ok');
    for (let i = 0; i < chips.length; i += 1) {
      if (!groupRunning) break;
      applyChip(chips[i], false);
      await analyze(chips[i].getAttribute('data-text') || '');
    }
    groupRunning = false;
    showStatus('Group run finished.', 'ok');
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    groupRunning = false;
    expectedTriage = '';
    document.querySelectorAll('.nct-chip.is-active').forEach(function (el) {
      el.classList.remove('is-active');
    });
    analyze(textarea.value);
  });

  textarea.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      form.requestSubmit();
    }
  });

  clearBtn.addEventListener('click', function () {
    groupRunning = false;
    textarea.value = '';
    expectedTriage = '';
    resultsEl.hidden = true;
    resultsBody.innerHTML = '';
    statusEl.hidden = true;
    document.querySelectorAll('.nct-chip.is-active').forEach(function (el) {
      el.classList.remove('is-active');
    });
    textarea.focus();
  });

  document.querySelectorAll('.nct-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      groupRunning = false;
      applyChip(chip, true);
    });
  });

  document.querySelectorAll('.nct-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      setGroupFilter(tab.getAttribute('data-group') || 'all');
    });
  });

  document.querySelectorAll('[data-run-group]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      runGroup(btn.getAttribute('data-run-group') || '');
    });
  });

  filterEl.addEventListener('input', filterChips);

  document.getElementById('nct-clear-log').addEventListener('click', function () {
    saveLog([]);
    renderLog();
  });

  document.getElementById('nct-export').addEventListener('click', function () {
    const rows = loadLog();
    const header = 'complaint,expected,actual,match,confidence,english,at';
    const csv = [header]
      .concat(
        rows.map(function (row) {
          return [row.complaint, row.expected, row.actual, row.match, row.confidence, row.english, row.at]
            .map(function (cell) {
              return '"' + String(cell ?? '').replace(/"/g, '""') + '"';
            })
            .join(',');
        })
      )
      .join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'nlp-complaint-trainer.csv';
    a.click();
    URL.revokeObjectURL(url);
  });

  renderLog();
})();
