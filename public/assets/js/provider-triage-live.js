(function () {
  'use strict';

  var cfg = window.MedConnectTriage || {};
  var REFRESH_MS = cfg.refreshMs || 15000;
  var pollTimer = null;
  var refreshInFlight = false;
  var currentTriageId = null;
  var activeFilter = 'all';
  var triageCaseCache = {};

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function attrEsc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function cacheTriageCases(cases) {
    if (!Array.isArray(cases)) return;
    cases.forEach(function (t) {
      if (t && t.id) {
        triageCaseCache[t.id] = t;
      }
    });
  }

  function readBootstrapCases() {
    var el = document.getElementById('triageCasesBootstrap');
    if (!el) return;
    try {
      cacheTriageCases(JSON.parse(el.textContent || '[]'));
    } catch (e) { /* ignore */ }
  }

  function getTriageCaseById(id) {
    return triageCaseCache[Number(id)] || null;
  }

  function applyRowFilter() {
    document.querySelectorAll('#triageTable tbody tr[data-urgency]').forEach(function (row) {
      if (activeFilter === 'all') {
        row.style.display = '';
        return;
      }
      if (activeFilter === 'reviewed') {
        row.style.display = (row.dataset.reviewed === 'true' && row.dataset.tipsPending !== 'true') ? '' : 'none';
        return;
      }
      if (activeFilter === 'pending') {
        row.style.display = row.dataset.pending === 'true' ? '' : 'none';
        return;
      }
      if (activeFilter === 'tips') {
        row.style.display = row.dataset.tipsPending === 'true' ? '' : 'none';
        return;
      }
      row.style.display = row.dataset.urgency === activeFilter ? '' : 'none';
    });
  }

  function renderWorkflow(t) {
    var badges = Array.isArray(t.workflow_badges) ? t.workflow_badges : [];
    if (!badges.length) {
      return '<span class="triage-badge triage-badge--pending">Pending</span>';
    }
    return badges.map(function (badge) {
      return '<span class="triage-badge triage-badge--' + esc(badge.class || 'pending') + '">'
        + esc(badge.label || '') + '</span>';
    }).join(' ');
  }

  function renderActions(t) {
    return '<div class="triage-actions">'
      + '<button type="button" class="mc-btn mc-btn--outline triage-view-btn" style="padding: 6px 12px; font-size: 11px;" data-triage-id="'
      + esc(t.id) + '">View Details</button>'
      + '</div>';
  }

  function renderRow(t) {
    var isUrgent = t.urgency === 'Urgent';
    var tipsPending = !!(t.needs_tips_approval || t.can_approve_recommendations);
    var rowClass = (isUrgent || tipsPending ? 'triage-row-urgent' : '') + (t.expired ? ' triage-row-expired' : '');
    var classification = isUrgent
      ? '<span class="triage-badge triage-badge--urgent">Urgent</span>'
      : '<span class="triage-badge triage-badge--routine">Non-Urgent</span>';
    if (t.label) {
      classification += '<div class="text-xs text-muted" style="margin-top: 4px;">' + esc(t.label) + '</div>';
    }

    return '<tr class="' + rowClass.trim() + '"'
      + ' data-urgency="' + (isUrgent ? 'urgent' : 'non-urgent') + '"'
      + ' data-reviewed="' + (t.reviewed ? 'true' : 'false') + '"'
      + ' data-pending="' + (t.reviewed ? 'false' : 'true') + '"'
      + ' data-tips-pending="' + (tipsPending ? 'true' : 'false') + '"'
      + ' data-expired="' + (t.expired ? 'true' : 'false') + '">'
      + '<td data-label="Patient" style="font-weight: 700; color: var(--mc-navy-dark);">' + esc(t.name) + '</td>'
      + '<td data-label="Patient Complaint"><span class="triage-complaint" title="' + esc(t.complaint || '—') + '">' + esc(t.complaint || '—') + '</span></td>'
      + '<td data-label="AI Classification">' + classification + '</td>'
      + '<td data-label="Submitted" style="white-space: nowrap; font-size: 12px; color: var(--mc-slate-muted);">' + esc(t.date) + '<br>' + esc(t.time) + '</td>'
      + '<td data-label="Status">' + renderWorkflow(t) + '</td>'
      + '<td data-label="Action">' + renderActions(t) + '</td>'
      + '</tr>';
  }

  function renderTable(cases) {
    var tbody = document.querySelector('#triageTable tbody');
    if (!tbody) return;

    if (!cases || !cases.length) {
      tbody.innerHTML = '<tr><td colspan="6"><div class="triage-empty"><p>No triage cases yet. New patient assessments will appear here.</p></div></td></tr>';
      return;
    }

    tbody.innerHTML = cases.map(renderRow).join('');
    applyRowFilter();
  }

  function updateStats(stats) {
    var urgentEl = document.getElementById('triageStatUrgent');
    var routineEl = document.getElementById('triageStatRoutine');
    var reviewedEl = document.getElementById('triageStatReviewed');
    var tipsEl = document.getElementById('triageStatTips');
    if (urgentEl) urgentEl.textContent = stats.urgent;
    if (routineEl) routineEl.textContent = stats.non_urgent;
    if (reviewedEl) reviewedEl.textContent = stats.reviewed;
    if (tipsEl) tipsEl.textContent = stats.tips_pending != null ? stats.tips_pending : 0;

    document.querySelectorAll('.triage-tab[data-filter]').forEach(function (tab) {
      var filter = tab.dataset.filter;
      var countEl = tab.querySelector('.triage-tab-count');
      if (!countEl) return;
      if (filter === 'all') countEl.textContent = stats.total;
      else if (filter === 'urgent') countEl.textContent = stats.urgent;
      else if (filter === 'non-urgent') countEl.textContent = stats.non_urgent;
      else if (filter === 'pending') countEl.textContent = stats.pending;
      else if (filter === 'tips') countEl.textContent = stats.tips_pending != null ? stats.tips_pending : 0;
      else if (filter === 'reviewed') countEl.textContent = stats.reviewed;
    });

    var summaryEl = document.getElementById('triageTableSummary');
    if (summaryEl) {
      var tips = stats.tips_pending != null ? Number(stats.tips_pending) : 0;
      summaryEl.textContent = (stats.total || 0) + ' total · ' + (stats.pending || 0) + ' pending review'
        + (tips ? ' · ' + tips + ' tips pending' : '');
    }

    // Push live totals to the sidebar badges.
    try {
      window.dispatchEvent(new CustomEvent('medconnect:triage-live', {
        detail: {
          triage: Number(stats.total || 0),
          total: Number(stats.total || 0),
          urgent: Number(stats.urgent || 0),
          pending: Number(stats.pending || 0),
          tips_pending: Number(stats.tips_pending || 0),
        },
      }));
    } catch (e) { /* ignore */ }
  }

  function setRefreshStatus(text) {
    var el = document.getElementById('triageRefreshStatus');
    if (el) el.textContent = text;
  }

  function formatPatientId(patientId) {
    var id = Number(patientId || 0);
    if (!id) return '—';
    return 'MC-' + String(id).padStart(6, '0');
  }

  function evidenceItems(evidence) {
    if (!evidence || !evidence.has_evidence) {
      return [];
    }
    if (Array.isArray(evidence.items) && evidence.items.length) {
      return evidence.items;
    }
    if (evidence.view_url) {
      return [evidence];
    }
    return [];
  }

  function renderEvidenceCard(item) {
    var url = String(item.view_url || '');
    if (!url) {
      return '';
    }
    var filename = esc(item.original_filename || 'Supporting evidence');
    var uploaded = esc(item.uploaded_label || '');
    var isVideo = item.media_type === 'video';
    var safeUrl = attrEsc(url);
    var preview = isVideo
      ? '<video src="' + safeUrl + '" controls playsinline preload="metadata" class="triage-evidence-card__media"></video>'
      : '<img src="' + safeUrl + '" alt="' + filename + '" class="triage-evidence-card__thumb">';
    var actionLabel = isVideo ? 'Open video' : 'View full size';
    var metaParts = [];
    if (item.original_filename) metaParts.push('<span class="triage-evidence-card__filename">' + filename + '</span>');
    if (uploaded) metaParts.push('<span class="triage-evidence-card__date">' + uploaded + '</span>');

    return '<article class="triage-evidence-card">'
      + '<div class="triage-evidence-card__preview">' + preview + '</div>'
      + '<div class="triage-evidence-card__body">'
      + (metaParts.length ? '<p class="triage-evidence-card__meta">' + metaParts.join('') + '</p>' : '')
      + '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer" class="mc-btn mc-btn--outline triage-evidence-card__btn">'
      + esc(actionLabel) + '</a>'
      + '</div>'
      + '</article>';
  }

  function renderSupportingEvidence(t) {
    var evidenceSection = document.getElementById('modalEvidenceSection');
    var listEl = document.getElementById('modalEvidenceList');
    var evidence = t && t.supporting_evidence && typeof t.supporting_evidence === 'object'
      ? t.supporting_evidence
      : {};
    var items = evidenceItems(evidence);

    if (!evidenceSection) return;

    if (!items.length) {
      evidenceSection.hidden = true;
      if (listEl) listEl.innerHTML = '';
      return;
    }

    evidenceSection.hidden = false;
    if (listEl) {
      listEl.innerHTML = items.map(renderEvidenceCard).join('');
    }
  }

  async function loadSupportingEvidence(triageId, caseData) {
    renderSupportingEvidence(caseData || {});
    if (!triageId || !cfg.evidenceApi) {
      return;
    }

    try {
      var res = await fetch(cfg.evidenceApi + '?triage_id=' + encodeURIComponent(String(triageId)), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      var data = await res.json().catch(function () { return null; });
      if (!data || !data.success || !data.supporting_evidence) {
        return;
      }

      var cached = getTriageCaseById(triageId);
      if (cached) {
        cached.supporting_evidence = data.supporting_evidence;
      }

      renderSupportingEvidence({ supporting_evidence: data.supporting_evidence });
    } catch (e) { /* ignore */ }
  }

  function autoResizeGuidanceTextarea() {
    var el = document.getElementById('modalRecommendationsEdit');
    if (!el) return;
    el.style.height = 'auto';
    var min = 180;
    el.style.height = Math.max(el.scrollHeight, min) + 'px';
  }

  function viewTriageDetails(t) {
    currentTriageId = t.id;
    document.getElementById('modalName').textContent = t.name || '—';
    var patientMetaEl = document.getElementById('modalPatientMeta');
    if (patientMetaEl) {
      var submitted = [t.date, t.time].filter(Boolean).join(' at ');
      patientMetaEl.textContent = 'Patient ID: ' + formatPatientId(t.patient_id)
        + (submitted ? ' · Submitted ' + submitted : '');
    }

    var complaintText = String(t.complaint || '').trim();
    var structuredSymptoms = Array.isArray(t.symptoms_list)
      ? t.symptoms_list.filter(function (symptom) {
          var value = String(symptom || '').trim();
          return value !== '' && value.toLowerCase() !== complaintText.toLowerCase();
        })
      : [];
    var symptomsHeading = document.getElementById('triagePatientWordsHeading');
    var symptomsSection = symptomsHeading ? symptomsHeading.closest('section') : null;
    if (symptomsSection) {
      symptomsSection.hidden = structuredSymptoms.length === 0;
    }
    document.getElementById('modalSymptoms').textContent = structuredSymptoms.length
      ? structuredSymptoms.join(', ')
      : (t.symptoms_display || '—');
    document.getElementById('modalComplaint').textContent = complaintText || 'No detailed complaint provided.';
    loadSupportingEvidence(t.id, t);
    document.getElementById('overrideLevel').value = t.level || '3';

    var urgencyEl = document.getElementById('modalUrgency');
    var triageLevel = String(t.triage_level || t.triage_classification || '').toUpperCase();
    if (triageLevel === 'EMERGENCY' || /emergency/i.test(String(t.label || ''))) {
      urgencyEl.innerHTML = '<span class="triage-badge triage-badge--urgent">Emergency</span>';
    } else if (t.urgency === 'Urgent') {
      urgencyEl.innerHTML = '<span class="triage-badge triage-badge--urgent">Urgent</span>';
    } else {
      urgencyEl.innerHTML = '<span class="triage-badge triage-badge--routine">Non-Urgent</span>';
    }

    var nlpPanel = document.getElementById('modalNlpAnalysis');
    var questionsSection = document.getElementById('modalQuestionsSection');
    var suggested = Array.isArray(t.suggested_questions) ? t.suggested_questions : [];
    var hasNlpAssessment =
      (t.english_complaint && String(t.english_complaint).trim()) ||
      (Array.isArray(t.detected_symptoms_ai) && t.detected_symptoms_ai.length) ||
      (Array.isArray(t.possible_conditions) && t.possible_conditions.length) ||
      (t.confidence_display && String(t.confidence_display).trim()) ||
      (t.triage_level && String(t.triage_level).trim());

    if (nlpPanel) {
      nlpPanel.hidden = !hasNlpAssessment;
    }
    if (questionsSection) {
      questionsSection.hidden = suggested.length === 0;
    }

    var setText = function (id, value, fallback) {
      var el = document.getElementById(id);
      if (el) el.textContent = value && String(value).trim() ? String(value) : (fallback || '—');
    };
    setText(
      'modalEnglishComplaint',
      t.english_complaint && String(t.english_complaint).trim() !== String(t.complaint || '').trim()
        ? t.english_complaint
        : '',
      t.english_complaint || 'Same as patient complaint / not translated'
    );
    setText(
      'modalDetectedSymptoms',
      Array.isArray(t.detected_symptoms_ai) && t.detected_symptoms_ai.length
        ? t.detected_symptoms_ai.join(', ')
        : '',
      'No symptoms extracted — confirm from patient submission'
    );

    var condEl = document.getElementById('modalPossibleConditions');
    if (condEl) {
      condEl.className = 'triage-modal-box';
      var conditions =
        Array.isArray(t.possible_conditions) && t.possible_conditions.length
          ? t.possible_conditions
          : ['Differential pending clinical review'];
      condEl.innerHTML = conditions
        .map(function (c) {
          return '<span class="triage-interp-chip">' + esc(String(c)) + '</span>';
        })
        .join('');
    }

    var confEl = document.getElementById('modalConfidence');
    if (confEl) {
      confEl.className = 'triage-modal-box triage-modal-box--metric';
      var confText = String(t.confidence_display || '').trim();
      var confVal = confText.replace(/[^\d.]/g, '');
      var confNum = parseFloat(confVal);
      if (!confText || isNaN(confNum)) {
        confText = 'Not scored';
        confNum = 0;
      } else if (confText.indexOf('%') < 0) {
        confText = String(Math.round(confNum)) + '%';
      }
      confEl.innerHTML =
        '<span class="triage-metric-value">' + esc(confText) + '</span>' +
        '<span class="triage-metric-bar" aria-hidden="true"><span style="width:' +
        Math.max(0, Math.min(100, confNum)) + '%"></span></span>';
    }

    setText(
      'modalTriageLevel',
      [t.triage_level || t.triage_classification, t.label].filter(Boolean).join(' · '),
      '—'
    );
    setText(
      'modalAssessedAt',
      [t.date, t.time].filter(Boolean).join(' at '),
      t.assessed_at || '—'
    );
    setText(
      'modalSuggestedQuestions',
      suggested.length ? suggested.map(function (q, i) { return (i + 1) + '. ' + q; }).join('\n') : '',
      suggested.length ? '' : '—'
    );
    setText('modalRecommendations', t.recommendations || '', '—');
    var recEdit = document.getElementById('modalRecommendationsEdit');
    if (recEdit) {
      recEdit.value = t.recommendations || '';
      autoResizeGuidanceTextarea();
    }
    var gateHint = document.getElementById('modalRecommendationGateHint');
    var recStatus = String(t.recommendation_status || 'hidden');
    var canDecideTips = !!(t.can_decide_care_tips || t.can_approve_recommendations);
    var isReviewed = !!t.reviewed;
    if (gateHint) {
      if (!t.complaint || !String(t.complaint).trim()) {
        gateHint.textContent = 'No patient complaint — NLP recommendations will not be shown to the patient.';
      } else if (recStatus === 'approved') {
        gateHint.textContent = 'Review complete. Approved self-care advice is available to the patient.';
      } else if (recStatus === 'rejected') {
        gateHint.textContent = 'Review complete. Self-care guidance was withheld from the patient.';
      } else if (canDecideTips) {
        gateHint.textContent = 'Choose Approve for Patient or Withhold Guidance to complete this review.';
      } else if (t.urgency === 'Urgent') {
        gateHint.textContent = 'Urgent case — use the existing urgent booking workflow. Self-care approval does not apply.';
      } else {
        gateHint.textContent = 'Patient-facing NLP recommendations are only released for non-urgent cases after provider approval.';
      }
    }
    var approveBtn = document.getElementById('modalApproveRecBtn');
    var rejectBtn = document.getElementById('modalRejectRecBtn');
    var reviewNote = document.getElementById('modalReviewStatusNote');
    if (approveBtn) approveBtn.style.display = canDecideTips ? 'inline-flex' : 'none';
    if (rejectBtn) rejectBtn.style.display = canDecideTips ? 'inline-flex' : 'none';
    if (recEdit) {
      recEdit.readOnly = !canDecideTips;
      recEdit.classList.toggle('is-readonly', !canDecideTips);
    }
    if (reviewNote) {
      if (isReviewed) {
        var note = recStatus === 'approved'
          ? 'Status: Tips approved · Reviewed'
          : recStatus === 'rejected'
            ? 'Status: Guidance withheld · Reviewed'
            : 'Status: Reviewed';
        if (t.is_booked) note += ' · Booked';
        reviewNote.textContent = note;
        reviewNote.hidden = false;
      } else if (t.is_terminated) {
        reviewNote.textContent = 'Status: Case terminated';
        reviewNote.hidden = false;
      } else if (t.expired) {
        reviewNote.textContent = 'This same-day case has expired.';
        reviewNote.hidden = false;
      } else {
        reviewNote.hidden = true;
        reviewNote.textContent = '';
      }
    }

    var caseActions = document.getElementById('triageCaseActions');
    var reportBtn = document.getElementById('modalReportCaseBtn');
    var terminateBtn = document.getElementById('modalTerminateCaseBtn');
    var activeReportNote = document.getElementById('modalActiveReportNote');
    if (caseActions) {
      caseActions.hidden = false;
      var terminated = !!t.is_terminated;
      var hasReport = !!t.has_active_report;
      var isEmergency = !!t.is_emergency;
      if (reportBtn) {
        reportBtn.disabled = hasReport;
        reportBtn.title = hasReport ? 'This case has already been reported and is currently under review.' : '';
      }
      if (terminateBtn) {
        terminateBtn.disabled = terminated || isEmergency;
        terminateBtn.title = isEmergency
          ? 'Emergency cases must follow the existing emergency clinical pathway.'
          : (terminated ? 'This case is already terminated.' : '');
      }
      if (activeReportNote) {
        if (hasReport) {
          activeReportNote.textContent = 'This case has already been reported and is currently under review.';
          activeReportNote.hidden = false;
        } else {
          activeReportNote.hidden = true;
          activeReportNote.textContent = '';
        }
      }
    }

    var modal = document.getElementById('triageModal');
    var scrollEl = modal ? modal.querySelector('.triage-modal__body') : null;
    if (scrollEl) scrollEl.scrollTop = 0;
    if (document.body) document.body.style.overflow = 'hidden';
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeTriageModal() {
    var modal = document.getElementById('triageModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (document.body) document.body.style.overflow = '';
    closeReviewConfirm('triageApproveConfirm');
    closeReviewConfirm('triageWithholdConfirm');
    closeReviewConfirm('triageReviewNotice');
  }

  function csrfToken() {
    return (document.body && document.body.dataset.csrf) || '';
  }

  async function postTriageAction(body) {
    var res = await fetch(cfg.updateApi, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: new URLSearchParams(body),
    });
    var raw = await res.text();
    var data = null;
    try {
      data = raw ? JSON.parse(raw) : null;
    } catch (parseErr) {
      var snippet = raw ? String(raw).replace(/\s+/g, ' ').trim().slice(0, 160) : '';
      throw new Error(
        snippet
          ? ('Server error: ' + snippet)
          : ('Server returned an invalid response (HTTP ' + res.status + '). Refresh and try again.')
      );
    }
    if (!res.ok && (!data || !data.message)) {
      return { success: false, message: 'Request failed (HTTP ' + res.status + '). Refresh and try again.' };
    }
    return data;
  }

  function openReviewConfirm(modalId) {
    var el = document.getElementById(modalId);
    if (!el) return null;
    el.hidden = false;
    el.setAttribute('aria-hidden', 'false');
    var focusBtn = el.querySelector('[data-mc-autofocus], .mc-btn--primary, .mc-btn--danger');
    if (focusBtn && typeof focusBtn.focus === 'function') {
      setTimeout(function () { focusBtn.focus(); }, 10);
    }
    return el;
  }

  function closeReviewConfirm(modalId) {
    var el = document.getElementById(modalId);
    if (!el) return;
    el.hidden = true;
    el.setAttribute('aria-hidden', 'true');
  }

  function askReviewConfirm(modalId, confirmAttr, cancelAttr) {
    return new Promise(function (resolve) {
      var el = openReviewConfirm(modalId);
      if (!el) {
        resolve(false);
        return;
      }

      function cleanup(result) {
        el.removeEventListener('click', onClick);
        document.removeEventListener('keydown', onKey);
        closeReviewConfirm(modalId);
        resolve(result);
      }

      function onClick(event) {
        if (event.target.closest('[' + cancelAttr + ']')) {
          cleanup(false);
          return;
        }
        if (event.target.closest('[' + confirmAttr + ']')) {
          cleanup(true);
        }
      }

      function onKey(event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          cleanup(false);
        }
      }

      el.addEventListener('click', onClick);
      document.addEventListener('keydown', onKey);
    });
  }

  function showReviewNotice(message, options) {
    options = options || {};
    var tone = options.tone === 'error' ? 'error' : 'success';
    var title = options.title || (tone === 'error' ? 'Something went wrong' : 'Review complete');
    var el = document.getElementById('triageReviewNotice');
    if (!el) {
      window.alert(message);
      return Promise.resolve();
    }

    var titleEl = document.getElementById('triageReviewNoticeTitle');
    var leadEl = document.getElementById('triageReviewNoticeLead');
    if (titleEl) titleEl.textContent = title;
    if (leadEl) leadEl.textContent = message || '';

    el.classList.toggle('triage-review-confirm--success', tone === 'success');
    el.classList.toggle('triage-review-confirm--error', tone === 'error');
    el.classList.remove('triage-review-confirm--danger');

    var okIcon = el.querySelector('[data-triage-notice-icon-success]');
    var errIcon = el.querySelector('[data-triage-notice-icon-error]');
    if (okIcon) okIcon.hidden = tone !== 'success';
    if (errIcon) errIcon.hidden = tone !== 'error';

    return askReviewConfirm(
      'triageReviewNotice',
      'data-triage-notice-ok',
      'data-triage-notice-ok'
    ).then(function () {
      return true;
    });
  }

  async function approveRecommendationsFromModal() {
    if (!currentTriageId) return;
    var recEdit = document.getElementById('modalRecommendationsEdit');
    var text = recEdit ? String(recEdit.value || '').trim() : '';
    if (!text) {
      await showReviewNotice('Add at least one self-care recommendation before approving.', {
        tone: 'error',
        title: 'Recommendations required',
      });
      return;
    }
    var confirmed = await askReviewConfirm(
      'triageApproveConfirm',
      'data-triage-approve-confirm',
      'data-triage-approve-cancel'
    );
    if (!confirmed) return;
    try {
      var data = await postTriageAction({
        id: String(currentTriageId),
        action: 'approve_recommendations',
        recommendations: text,
        csrf_token: csrfToken(),
      });
      if (!data || !data.success) {
        await showReviewNotice((data && data.message) || 'Could not approve recommendations.', {
          tone: 'error',
          title: 'Approval failed',
        });
        return;
      }
      closeTriageModal();
      refreshTriage(true);
      await showReviewNotice(
        data.message || 'Review complete. Self-care recommendations are now available to the patient.',
        { title: 'Review complete' }
      );
    } catch (e) {
      await showReviewNotice((e && e.message) || 'Could not approve recommendations.', {
        tone: 'error',
        title: 'Approval failed',
      });
    }
  }

  async function rejectRecommendationsFromModal() {
    if (!currentTriageId) return;
    var confirmed = await askReviewConfirm(
      'triageWithholdConfirm',
      'data-triage-withhold-confirm',
      'data-triage-withhold-cancel'
    );
    if (!confirmed) return;
    try {
      var data = await postTriageAction({
        id: String(currentTriageId),
        action: 'reject_recommendations',
        csrf_token: csrfToken(),
      });
      if (!data || !data.success) {
        await showReviewNotice((data && data.message) || 'Could not update recommendations.', {
          tone: 'error',
          title: 'Update failed',
        });
        return;
      }
      closeTriageModal();
      refreshTriage(true);
      if (window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
      await showReviewNotice(
        data.message || 'Review complete. Self-care guidance was withheld from the patient.',
        { title: 'Review complete' }
      );
    } catch (e) {
      await showReviewNotice('Could not update recommendations.', {
        tone: 'error',
        title: 'Update failed',
      });
    }
  }

  async function applyOverride() {
    var level = document.getElementById('overrideLevel').value;
    if (!currentTriageId) return;
    if (!confirm('Are you sure you want to manually override the AI priority level?')) return;
    try {
      var res = await fetch(cfg.updateApi, {
        method: 'POST',
        credentials: 'same-origin',
        body: new URLSearchParams({
          id: String(currentTriageId),
          action: 'override',
          level: level,
          csrf_token: csrfToken(),
        }),
      });
      var data = await res.json();
      if (data.success) {
        closeTriageModal();
        refreshTriage(true);
        if (window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
      } else {
        alert(data.message || 'Could not update priority.');
      }
    } catch (e) {
      alert('Error updating priority.');
    }
  }

  async function postCaseAction(url, body) {
    var res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: new URLSearchParams(body),
    });
    var raw = await res.text();
    var data = null;
    try {
      data = raw ? JSON.parse(raw) : null;
    } catch (e) {
      throw new Error('Invalid server response.');
    }
    return data;
  }

  function openTriageSubModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.add('is-open');
    el.setAttribute('aria-hidden', 'false');
  }

  function closeTriageSubModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('is-open');
    el.setAttribute('aria-hidden', 'true');
  }

  async function submitCaseReport() {
    if (!currentTriageId) return;
    var reasonEl = document.getElementById('triageReportReason');
    var notesEl = document.getElementById('triageReportNotes');
    var reason = reasonEl ? String(reasonEl.value || '').trim() : '';
    if (!reason) {
      alert('Please select a report reason.');
      return;
    }
    try {
      var data = await postCaseAction(cfg.reportApi, {
        triage_id: String(currentTriageId),
        reason: reason,
        notes: notesEl ? String(notesEl.value || '') : '',
        csrf_token: csrfToken(),
      });
      if (!data || !data.success) {
        alert((data && data.message) || 'Could not submit report.');
        return;
      }
      alert(data.message || 'Report submitted.');
      closeTriageSubModal('triageReportModal');
      closeTriageModal();
      refreshTriage(true);
    } catch (e) {
      alert('Could not submit report.');
    }
  }

  async function submitCaseTerminate() {
    if (!currentTriageId) return;
    var reasonEl = document.getElementById('triageTerminateReason');
    var reason = reasonEl ? String(reasonEl.value || '').trim() : '';
    if (reason.length < 5) {
      alert('Please provide a reason (at least 5 characters).');
      return;
    }
    try {
      var data = await postCaseAction(cfg.terminateApi, {
        triage_id: String(currentTriageId),
        reason: reason,
        csrf_token: csrfToken(),
      });
      if (!data || !data.success) {
        alert((data && data.message) || 'Could not terminate case.');
        return;
      }
      alert(data.message || 'Case terminated.');
      closeTriageSubModal('triageTerminateModal');
      closeTriageModal();
      refreshTriage(true);
      if (window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
    } catch (e) {
      alert('Could not terminate case.');
    }
  }

  async function refreshTriage(silent) {
    if (refreshInFlight || !cfg.listApi) return;
    var modal = document.getElementById('triageModal');
    if (modal && modal.classList.contains('is-open')) return;

    refreshInFlight = true;
    try {
      var url = cfg.listApi + (cfg.tab ? '?tab=' + encodeURIComponent(cfg.tab) : '');
      var res = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      var data = await res.json();
      if (!data.success) {
        if (!silent) setRefreshStatus('Refresh paused');
        return;
      }

      renderTable(data.cases || []);
      cacheTriageCases(data.cases || []);
      updateStats(data.stats || {});
      setRefreshStatus('Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
    } catch (e) {
      if (!silent) setRefreshStatus('Refresh paused');
    } finally {
      refreshInFlight = false;
    }
  }

  function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(function () {
      if (document.hidden) return;
      if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
      refreshTriage(true);
    }, REFRESH_MS);
  }

  function stopPolling() {
    if (!pollTimer) return;
    clearInterval(pollTimer);
    pollTimer = null;
  }

  function bindUi() {
    document.querySelector('#triageTable')?.addEventListener('click', function (event) {
      var viewBtn = event.target.closest('.triage-view-btn');
      if (!viewBtn) return;
      var triageId = Number(viewBtn.dataset.triageId || 0);
      var payload = triageId ? getTriageCaseById(triageId) : null;
      if (payload) viewTriageDetails(payload);
    });

    document.querySelectorAll('.triage-tab[data-filter]').forEach(function (tab) {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.triage-tab[data-filter]').forEach(function (t) {
          t.classList.remove('active');
        });
        tab.classList.add('active');
        activeFilter = tab.dataset.filter || 'all';
        applyRowFilter();
      });
    });

    document.getElementById('triageModal')?.addEventListener('click', function (event) {
      if (event.target.id === 'triageModal') closeTriageModal();
    });

    document.getElementById('modalReportCaseBtn')?.addEventListener('click', function () {
      openTriageSubModal('triageReportModal');
    });
    document.getElementById('modalTerminateCaseBtn')?.addEventListener('click', function () {
      openTriageSubModal('triageTerminateModal');
    });
    document.querySelector('[data-triage-report-cancel]')?.addEventListener('click', function () {
      closeTriageSubModal('triageReportModal');
    });
    document.querySelector('[data-triage-report-submit]')?.addEventListener('click', submitCaseReport);
    document.querySelector('[data-triage-terminate-cancel]')?.addEventListener('click', function () {
      closeTriageSubModal('triageTerminateModal');
    });
    document.querySelector('[data-triage-terminate-submit]')?.addEventListener('click', submitCaseTerminate);
    ['triageReportModal', 'triageTerminateModal'].forEach(function (modalId) {
      document.getElementById(modalId)?.addEventListener('click', function (event) {
        if (event.target.id === modalId) closeTriageSubModal(modalId);
      });
    });

    var recEdit = document.getElementById('modalRecommendationsEdit');
    if (recEdit && !recEdit.dataset.resizeBound) {
      recEdit.dataset.resizeBound = '1';
      recEdit.addEventListener('input', autoResizeGuidanceTextarea);
    }
  }

  window.closeTriageModal = closeTriageModal;
  window.applyOverride = applyOverride;
  window.approveRecommendationsFromModal = approveRecommendationsFromModal;
  window.rejectRecommendationsFromModal = rejectRecommendationsFromModal;

  bindUi();
  readBootstrapCases();
  refreshTriage(true);
  startPolling();
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stopPolling();
      return;
    }
    startPolling();
    refreshTriage(true);
  });
  document.addEventListener('medconnect:live-sync', function (ev) {
    var changed = (ev.detail && ev.detail.changed) || [];
    if (changed.indexOf('triage') !== -1 || changed.indexOf('queue') !== -1) {
      refreshTriage(true);
    }
  });

  window.MedConnectTriageLive = { refresh: refreshTriage };
})();
