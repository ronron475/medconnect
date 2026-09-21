/**
 * Gemini Clinical Interview Demo — client (DEMO ONLY).
 * API keys never leave the server; only CSRF + demo session token are sent.
 */
(function () {
  'use strict';

  var body = document.body;
  var csrf = body.getAttribute('data-csrf') || '';
  var demoToken = body.getAttribute('data-demo-token') || '';
  var base = window.APP_BASE || '';
  var apiUrl = base + '/app/api/ai/gemini_clinical_interview_demo.php';

  var complaintEl = document.getElementById('gci-complaint');
  var startBtn = document.getElementById('gci-start');
  var resetBtn = document.getElementById('gci-reset');
  var answerWrap = document.getElementById('gci-answer-wrap');
  var answerEl = document.getElementById('gci-answer');
  var submitBtn = document.getElementById('gci-submit-answer');
  var conversationEl = document.getElementById('gci-conversation');
  var statusEl = document.getElementById('gci-status');
  var factsEl = document.getElementById('gci-facts');
  var missingEl = document.getElementById('gci-missing');
  var triageEl = document.getElementById('gci-triage');
  var triageReasonEl = document.getElementById('gci-triage-reason');
  var debugEl = document.getElementById('gci-debug');

  var interviewContext = null;
  var busy = false;

  function setBusy(on) {
    busy = !!on;
    if (startBtn) startBtn.disabled = busy;
    if (submitBtn) submitBtn.disabled = busy;
    if (resetBtn) resetBtn.disabled = busy;
  }

  function setStatus(status, label) {
    if (!statusEl) return;
    statusEl.setAttribute('data-status', status || 'idle');
    statusEl.textContent = 'Status: ' + (label || status || 'Ready');
  }

  function renderConversation(rows) {
    if (!conversationEl) return;
    conversationEl.innerHTML = '';
    (rows || []).forEach(function (row) {
      if (!row || !row.text) return;
      var role = String(row.role || 'system').toLowerCase();
      var div = document.createElement('div');
      div.className = 'gci-bubble gci-bubble--' + (role === 'patient' ? 'patient' : role === 'gemini' ? 'gemini' : 'system');
      var who = role === 'patient' ? 'Patient' : role === 'gemini' ? 'Gemini' : 'System';
      div.innerHTML = '<strong>' + who + '</strong>' + escapeHtml(String(row.text));
      conversationEl.appendChild(div);
    });
    conversationEl.scrollTop = conversationEl.scrollHeight;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderFacts(facts, missing) {
    if (factsEl) {
      factsEl.textContent = JSON.stringify(facts || {}, null, 2);
    }
    if (missingEl) {
      var list = Array.isArray(missing) ? missing : [];
      missingEl.textContent = list.length ? list.join(', ') : '—';
    }
  }

  function renderTriage(finalTriage) {
    if (!triageEl) return;
    triageEl.className = 'gci-triage gci-triage--idle';
    if (!finalTriage || !finalTriage.triage_display) {
      triageEl.textContent = 'Not finalized';
      if (triageReasonEl) triageReasonEl.textContent = '';
      return;
    }
    var display = String(finalTriage.triage_display);
    triageEl.className = 'gci-triage gci-triage--' + display;
    triageEl.textContent = display;
    if (triageReasonEl) {
      var bits = [];
      if (finalTriage.final_authority) bits.push('Authority: ' + finalTriage.final_authority);
      if (finalTriage.confidence_score != null) bits.push('Confidence: ' + finalTriage.confidence_score + '%');
      if (finalTriage.reason) bits.push(String(finalTriage.reason));
      triageReasonEl.textContent = bits.join(' · ');
    }
  }

  function renderDebug(payload) {
    if (!debugEl) return;
    debugEl.textContent = JSON.stringify(payload && payload.debug ? payload.debug : payload || {}, null, 2);
  }

  function applyPayload(payload) {
    if (!payload) return;
    if (payload.needs_health_concern || payload.rejected) {
      interviewContext = null;
    } else {
      interviewContext = payload.interview_context || interviewContext;
    }
    setStatus(payload.status || 'idle', payload.status_label || payload.message || payload.status);
    renderConversation(payload.conversation || []);
    renderFacts(payload.clinical_facts, payload.missing_information);
    renderTriage(payload.final_triage);
    renderDebug(payload);

    var interviewing = payload.status === 'interviewing' && payload.awaiting_question;
    if (answerWrap) {
      answerWrap.hidden = !interviewing;
    }
    if (interviewing && answerEl) {
      answerEl.value = '';
      answerEl.focus();
    }
    if (payload.status === 'final_triage' && complaintEl) {
      complaintEl.readOnly = true;
    }
    if ((payload.needs_health_concern || payload.rejected) && complaintEl) {
      complaintEl.readOnly = false;
    }
  }

  function post(fields) {
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('demo_token', demoToken);
    Object.keys(fields || {}).forEach(function (k) {
      fd.append(k, fields[k]);
    });
    return fetch(apiUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(function (res) {
      return res.json().then(function (json) {
        return { ok: res.ok, status: res.status, json: json };
      });
    });
  }

  function unwrap(json) {
    if (!json) return null;
    if (json.demo && json.interview_context !== undefined) return json;
    if (json.data && typeof json.data === 'object') return json.data;
    return json;
  }

  function startInterview() {
    if (busy) return;
    var complaint = (complaintEl && complaintEl.value ? complaintEl.value : '').trim();
    if (!complaint) {
      setStatus('error', 'Enter a complaint first');
      return;
    }
    setBusy(true);
    setStatus('interviewing', 'Calling Gemini…');
    post({
      action: 'start',
      chief_complaint: complaint,
    })
      .then(function (res) {
        var payload = unwrap(res.json);
        if (!res.ok || (res.json && res.json.success === false)) {
          var msg = (res.json && (res.json.message || (res.json.error && res.json.error.message))) || 'Start failed';
          var demoPayload = null;
          if (payload && payload.demo) demoPayload = payload.demo;
          else if (res.json && res.json.demo) demoPayload = res.json.demo;
          else if (payload && payload.needs_health_concern) demoPayload = payload;
          if (demoPayload) {
            applyPayload(demoPayload);
          }
          setStatus(
            (demoPayload && demoPayload.status) || 'error',
            (demoPayload && (demoPayload.status_label || demoPayload.message)) || msg
          );
          renderDebug(demoPayload || payload || res.json);
          return;
        }
        applyPayload(payload);
      })
      .catch(function (err) {
        setStatus('error', 'Network error');
        renderDebug({ error: String(err && err.message ? err.message : err) });
      })
      .finally(function () {
        setBusy(false);
      });
  }

  function submitAnswer() {
    if (busy) return;
    var answer = (answerEl && answerEl.value ? answerEl.value : '').trim();
    if (!answer) {
      setStatus('error', 'Enter an answer');
      return;
    }
    if (!interviewContext) {
      setStatus('error', 'Start an interview first');
      return;
    }
    setBusy(true);
    setStatus('interviewing', 'Interpreting answer…');
    post({
      action: 'answer',
      answer: answer,
      interview_context: JSON.stringify(interviewContext),
    })
      .then(function (res) {
        var payload = unwrap(res.json);
        if (!res.ok || (res.json && res.json.success === false)) {
          var msg = (res.json && res.json.message) || 'Answer failed';
          if (payload && (payload.demo || payload.interview_context)) {
            applyPayload(payload.demo || payload);
          }
          setStatus('error', msg);
          renderDebug(payload || res.json);
          return;
        }
        applyPayload(payload);
      })
      .catch(function (err) {
        setStatus('error', 'Network error');
        renderDebug({ error: String(err && err.message ? err.message : err) });
      })
      .finally(function () {
        setBusy(false);
      });
  }

  function resetDemo() {
    interviewContext = null;
    if (complaintEl) {
      complaintEl.value = '';
      complaintEl.readOnly = false;
    }
    if (answerEl) answerEl.value = '';
    if (answerWrap) answerWrap.hidden = true;
    setStatus('idle', 'Ready');
    renderConversation([]);
    renderFacts({}, []);
    renderTriage(null);
    renderDebug({});
  }

  if (startBtn) startBtn.addEventListener('click', startInterview);
  if (submitBtn) submitBtn.addEventListener('click', submitAnswer);
  if (resetBtn) resetBtn.addEventListener('click', resetDemo);

  document.querySelectorAll('.gci-chip[data-text]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (complaintEl && !complaintEl.readOnly) {
        complaintEl.value = btn.getAttribute('data-text') || '';
        complaintEl.focus();
      }
    });
  });

  if (answerEl) {
    answerEl.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey) {
        ev.preventDefault();
        submitAnswer();
      }
    });
  }
})();
