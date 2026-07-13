/**
 * BHW Triage & Book Consultation — NLP-powered clinical input.
 */
(function () {
  'use strict';

  var form = document.getElementById('bhwTriageForm');
  if (!form || typeof window.BhwPortal === 'undefined') return;

  // Dismiss page boot loader so triage UI is interactive (never leave a dark overlay stuck).
  function dismissPageLoader() {
    var loader = window.MedConnectGlobalLoader || window.MedConnectLoader;
    if (loader && typeof loader.forceHide === 'function') {
      loader.forceHide();
    }
    var boot = document.getElementById('mc-loader-boot');
    if (boot) {
      boot.classList.remove('mc-global-loader--visible', 'mc-loader--visible', 'mc-global-loader--modal');
      boot.setAttribute('hidden', '');
      boot.setAttribute('aria-hidden', 'true');
      boot.setAttribute('aria-busy', 'false');
    }
    document.body.classList.remove(
      'mc-global-loader-active',
      'mc-loader-active',
      'mc-login-loading-active',
      'mc-global-loader--boot-active',
      'mc-global-loader--modal-active'
    );
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', dismissPageLoader);
  } else {
    dismissPageLoader();
  }

  var config = window.bhwTriageConfig || {};
  var pre = parseInt(config.preselect, 10) || 0;
  var showPreview = !!config.showPreview;
  var isEmergency = false;
  var isUrgent = false;
  var currentRouting = null;
  var assessmentToken = '';
  var assessmentPatientId = 0;
  var lastAssessedComplaint = '';
  var cachedAssessmentResult = null;
  var providerMeta = {};

  var nlpInputEl = document.getElementById('bhwNlpInput');
  var emergencyBanner = document.getElementById('bhwEmergencyBanner');
  var slotWrap = document.getElementById('bhwSlotWrap');
  var consentWrap = document.getElementById('bhwConsentWrap');
  var consentSection = document.getElementById('bhwConsentSection');
  var submitBtn = document.getElementById('bhwSubmitBtn');
  var slotEl = document.getElementById('bhwSlot');
  var providerEl = document.getElementById('bhwProvider');
  var patientEl = document.getElementById('bhwPatient');
  var verifyBtn = document.getElementById('bhwVerifyBtn');
  var verifyBtnLabel = document.getElementById('bhwVerifyBtnLabel');
  var verifyStatusEl = document.getElementById('bhwVerifyStatus');
  var providerHintEl = document.getElementById('bhwProviderSchedule');
  var slotHintEl = document.getElementById('bhwSlotHint');
  var emergencyOverlay = document.getElementById('bhwEmergencyOverlay');
  var assessmentTokenEl = document.getElementById('bhwAssessmentToken');
  var bookingSection = document.getElementById('bhwBookingSection');
  var assessmentSection = document.getElementById('bhwAssessmentSection');
  var assessmentPanelEl = document.getElementById('bhwAssessmentPanel');
  var schedulingNoteEl = document.getElementById('bhwSchedulingNote');
  var nlpCharCountEl = document.getElementById('bhwNlpCharCount');
  var nlpErrorEl = document.getElementById('bhwNlpError');

  var PATIENT_MSG_REQUIRED = 'Select a patient before running triage.';

  var COMPLAINT_MAX = 500;
  var COMPLAINT_MSG_INVALID = 'Please describe your main health concern in your own words.';
  var COMPLAINT_MSG_LIMIT = 'You have reached the maximum limit of 500 characters.';
  var TEXTAREA_MAX_HEIGHT = 280;
  var KEYBOARD_SMASH_RE = /^(asd|qwe|zxc|jkl|hjkl|asdf|qwerty|xxx|aaa|bbb|kkk|test|blah|qwertyuiop|asdfghjkl)+$/i;
  var EMOJI_RE = /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/gu;
  var LETTER_RE = /\p{L}/u;
  var LETTERS_RE = /\p{L}/gu;
  var VOWEL_RE = /[aeiouàáâäãåæèéêëìíîïòóôöùúûüýÿ]/i;

  function formalLoader() {
    return window.MedConnectGlobalLoader || window.MedConnectLoader || null;
  }

  function showFormalLoader(opts) {
    var L = formalLoader();
    if (L && typeof L.showFormal === 'function') {
      L.showFormal(opts || { preset: 'ai' });
      return;
    }
    if (BhwPortal && BhwPortal.loader && BhwPortal.loader.showFormal) {
      BhwPortal.loader.showFormal(opts || { preset: 'ai' });
    }
  }

  function hideFormalLoader() {
    var L = formalLoader();
    if (L && typeof L.hideFormal === 'function') {
      L.hideFormal();
      return;
    }
    if (BhwPortal && BhwPortal.loader && BhwPortal.loader.hideFormal) {
      BhwPortal.loader.hideFormal();
    }
  }

  function forceHideLoader() {
    var L = formalLoader();
    if (L && typeof L.forceHide === 'function') {
      L.forceHide();
    }
    if (BhwPortal && typeof BhwPortal.releaseUiBlockers === 'function') {
      BhwPortal.releaseUiBlockers();
    } else {
      hideFormalLoader();
    }
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function trimComplaint(text) {
    return String(text || '').replace(/^\s+/, '').replace(/\s+$/, '');
  }

  function getConcernText() {
    if (!nlpInputEl) return '';
    return trimComplaint(nlpInputEl.value);
  }

  function countComplaintChars(text) {
    return String(text || '').length;
  }

  function enforceComplaintLimit() {
    if (!nlpInputEl) return;
    if (nlpInputEl.value.length > COMPLAINT_MAX) {
      nlpInputEl.value = nlpInputEl.value.slice(0, COMPLAINT_MAX);
    }
  }

  function autoResizeComplaintInput() {
    if (!nlpInputEl) return;
    nlpInputEl.style.height = 'auto';
    var nextHeight = Math.min(nlpInputEl.scrollHeight, TEXTAREA_MAX_HEIGHT);
    nlpInputEl.style.height = nextHeight + 'px';
    nlpInputEl.style.overflowY = nlpInputEl.scrollHeight > TEXTAREA_MAX_HEIGHT ? 'auto' : 'hidden';
  }

  function updateComplaintCounter() {
    if (!nlpCharCountEl || !nlpInputEl) return;
    var len = countComplaintChars(nlpInputEl.value);
    var remaining = Math.max(0, COMPLAINT_MAX - len);
    nlpCharCountEl.textContent = len + ' / ' + COMPLAINT_MAX;
    nlpCharCountEl.setAttribute('aria-label', remaining + ' characters remaining out of ' + COMPLAINT_MAX);
    nlpCharCountEl.classList.toggle('is-near-limit', len >= COMPLAINT_MAX - 50 && len < COMPLAINT_MAX);
    nlpCharCountEl.classList.toggle('is-at-limit', len >= COMPLAINT_MAX);
    nlpInputEl.classList.toggle('is-at-limit', len >= COMPLAINT_MAX);
  }

  function setComplaintError(message) {
    if (!nlpErrorEl) return;
    if (message) {
      nlpErrorEl.textContent = message;
      nlpErrorEl.hidden = false;
    } else {
      nlpErrorEl.textContent = '';
      nlpErrorEl.hidden = true;
    }
    if (nlpInputEl) {
      nlpInputEl.setAttribute('aria-invalid', message ? 'true' : 'false');
      nlpInputEl.classList.toggle('is-invalid', !!message);
    }
  }

  function isWhitespaceOnly(text) {
    return trimComplaint(text) === '';
  }

  function isEmojiOnly(text) {
    var stripped = String(text || '').replace(EMOJI_RE, '').replace(/\s/g, '');
    return stripped.length === 0 && EMOJI_RE.test(String(text || ''));
  }

  function isNumbersOnly(text) {
    var trimmed = trimComplaint(text);
    if (!trimmed) return false;
    return !LETTER_RE.test(trimmed) && /^[\d\s.,:;+\-()%/\\]+$/.test(trimmed);
  }

  function isGibberishComplaint(text) {
    var trimmed = trimComplaint(text);
    if (!trimmed) return true;
    if (trimmed.length < 4) return true;

    var letters = (trimmed.match(LETTERS_RE) || []).length;
    if (letters === 0) return true;

    var words = trimmed.split(/\s+/).filter(function (w) {
      return w.length > 0;
    });
    if (!words.length) return true;

    var compact = trimmed.replace(/\s/g, '').toLowerCase();
    if (/^(.)\1{2,}$/i.test(compact)) return true;
    if (KEYBOARD_SMASH_RE.test(compact)) return true;
    if (/(.)\1{4,}/i.test(trimmed)) return true;

    var vowels = (trimmed.match(VOWEL_RE) || []).length;
    if (vowels === 0) return true;

    var wordLike = words.filter(function (w) {
      var alphaCount = (w.match(LETTERS_RE) || []).length;
      return alphaCount >= 2 || (alphaCount === 1 && w.length <= 4);
    });
    if (!wordLike.length) return true;

    if (words.length >= 2) {
      var suspicious = words.filter(function (w) {
        var alpha = (w.match(LETTERS_RE) || []).join('');
        if (alpha.length < 4) return false;
        var wordVowels = (alpha.match(VOWEL_RE) || []).length;
        return wordVowels / alpha.length < 0.12;
      });
      if (suspicious.length === words.length) return true;
    }

    return false;
  }

  function validateChiefComplaint(options) {
    options = options || {};
    var showErrors = !!options.showErrors;
    var raw = nlpInputEl ? nlpInputEl.value : '';
    var trimmed = trimComplaint(raw);
    var len = countComplaintChars(raw);
    var atLimit = len >= COMPLAINT_MAX;
    var invalid = !trimmed
      || isWhitespaceOnly(raw)
      || isEmojiOnly(raw)
      || isNumbersOnly(raw)
      || isGibberishComplaint(raw);
    var message = '';

    if (invalid) {
      message = COMPLAINT_MSG_INVALID;
    } else if (atLimit) {
      message = COMPLAINT_MSG_LIMIT;
    }

    if (showErrors) {
      setComplaintError(message);
    }

    return {
      ok: !invalid,
      message: message,
      atLimit: atLimit,
      trimmed: trimmed,
    };
  }

  function prepareComplaintForSubmit() {
    if (!nlpInputEl) return '';
    enforceComplaintLimit();
    var trimmed = trimComplaint(nlpInputEl.value);
    if (nlpInputEl.value !== trimmed) {
      nlpInputEl.value = trimmed;
      autoResizeComplaintInput();
      updateComplaintCounter();
    }
    return trimmed;
  }

  function setVerifyLoading(loading) {
    if (verifyBtn) {
      verifyBtn.disabled = !!loading;
      verifyBtn.classList.toggle('is-loading', !!loading);
    }
    if (verifyBtnLabel) {
      verifyBtnLabel.textContent = loading ? 'Running…' : 'Run';
    }
    if (loading) {
      setVerifyStatus('Running AI triage — please wait…', 'info');
    }
  }

  function setVerifyStatus(message, tone) {
    if (!verifyStatusEl) return;
    verifyStatusEl.textContent = message || '';
    verifyStatusEl.className = 'bhw-triage-nlp-actions__note' + (tone ? ' is-' + tone : '');
  }


  function triageStatusMessage(routing, isEmergency) {
    if (isEmergency) {
      return 'Emergency classification — proceed with referral.';
    }
    if (routing && routing.tier === 'urgent') {
      return 'Urgent classification — select a priority slot.';
    }
    return 'Triage complete — you may proceed to schedule.';
  }

  function getSelectedPatientId() {
    if (!patientEl) return 0;
    return parseInt(patientEl.value, 10) || 0;
  }

  function validatePatientSelected(showErrors) {
    var id = getSelectedPatientId();
    if (id > 0) return id;
    if (showErrors) {
      setVerifyStatus(PATIENT_MSG_REQUIRED, 'error');
      if (patientEl && typeof patientEl.focus === 'function') {
        patientEl.focus();
      }
    }
    return 0;
  }

  function clearAssessmentState() {
    assessmentToken = '';
    assessmentPatientId = 0;
    lastAssessedComplaint = '';
    cachedAssessmentResult = null;
    isEmergency = false;
    isUrgent = false;
    currentRouting = null;
    if (assessmentTokenEl) assessmentTokenEl.value = '';
    if (assessmentPanelEl) assessmentPanelEl.innerHTML = '';
    if (assessmentSection) assessmentSection.hidden = true;
    if (bookingSection) {
      bookingSection.hidden = true;
      bookingSection.style.display = 'none';
    }
    if (consentSection) consentSection.hidden = true;
    if (emergencyBanner) emergencyBanner.style.display = 'none';
    if (emergencyOverlay) {
      emergencyOverlay.hidden = true;
      emergencyOverlay.setAttribute('aria-hidden', 'true');
    }
    if (submitBtn) {
      submitBtn.textContent = 'Submit Triage & Book';
      submitBtn.classList.add('bhw-triage-btn--primary');
      submitBtn.classList.remove('bhw-triage-btn--emergency');
    }
    if (slotEl) {
      slotEl.setAttribute('required', 'required');
      slotEl.classList.remove('bhw-triage-slot--priority');
    }
    setVerifyStatus('');
  }

  function storeAssessmentToken(token, patientId) {
    if (!token) return;
    assessmentToken = String(token);
    assessmentPatientId = patientId || getSelectedPatientId();
    if (assessmentTokenEl) assessmentTokenEl.value = assessmentToken;
  }

  function canReuseAssessment(complaint, patientId) {
    return !!(cachedAssessmentResult
      && cachedAssessmentResult.success
      && cachedAssessmentResult.assessment
      && assessmentToken
      && complaint === lastAssessedComplaint
      && patientId > 0
      && patientId === assessmentPatientId);
  }

  function renderListItems(items, emptyLabel) {
    if (!items || !items.length) {
      return '<p class="bhw-triage-assessment__empty">' + escapeHtml(emptyLabel) + '</p>';
    }
    return '<ul class="bhw-triage-assessment__list">' + items.map(function (item) {
      return '<li>' + escapeHtml(String(item)) + '</li>';
    }).join('') + '</ul>';
  }

  function renderPipelineSteps(steps) {
    if (!steps || !steps.length) return '';
    return '<div class="bhw-triage-assessment__block">' +
      '<span class="bhw-triage-assessment__label">NLP pipeline</span>' +
      '<ol class="bhw-triage-pipeline-steps">' +
      steps.map(function (step) {
        var done = step.status === 'complete';
        return '<li class="bhw-triage-pipeline-steps__item' + (done ? ' is-complete' : '') + '">' +
          '<span class="bhw-triage-pipeline-steps__num">' + escapeHtml(String(step.id || '')) + '</span>' +
          '<span class="bhw-triage-pipeline-steps__label">' + escapeHtml(step.label || '') + '</span>' +
          '</li>';
      }).join('') +
      '</ol></div>';
  }

  function renderAssessmentPanel(r) {
    if (!assessmentPanelEl || !assessmentSection) return;

    var assessment = (r && r.assessment) || {};
    var pipeline = (r && r.pipeline) || {};
    var routing = (r && r.routing) || {};
    var tier = (routing.tier || '').toLowerCase();
    var isEmerg = tier === 'emergency' || !!r.is_emergency;
    var isUrg = tier === 'urgent' || !!r.is_urgent;

    var urgencyLabel = assessment.urgency_label
      || (routing.label || '')
      || (assessment.triage && assessment.triage.triage_classification)
      || 'Routine';
    var classification = (assessment.triage && assessment.triage.triage_classification) || urgencyLabel;
    var english = assessment.english_translation || pipeline.english_translation || '';
    var symptoms = assessment.detected_symptoms || [];
    var conditions = assessment.possible_conditions || [];
    var recommendations = assessment.recommendations || [];
    var confidence = assessment.confidence && assessment.confidence.score != null
      ? assessment.confidence.score + '%'
      : (pipeline.confidence && pipeline.confidence.score != null ? pipeline.confidence.score + '%' : '—');

    var panelClass = 'bhw-triage-assessment';
    if (isEmerg) panelClass += ' is-emergency';
    else if (isUrg) panelClass += ' is-urgent';

    assessmentPanelEl.className = panelClass;
    assessmentPanelEl.innerHTML =
      '<div class="bhw-triage-assessment__urgency">' +
        '<span class="bhw-triage-assessment__badge">' + escapeHtml(String(classification)) + '</span>' +
        '<span class="bhw-triage-assessment__level">' + escapeHtml(String(urgencyLabel)) + ' priority</span>' +
      '</div>' +
      '<dl class="bhw-triage-assessment__meta">' +
        '<div><dt>Routing</dt><dd>' + escapeHtml(routing.message || routing.mode || '—') + '</dd></div>' +
        '<div><dt>Confidence</dt><dd>' + escapeHtml(String(confidence)) + '</dd></div>' +
      '</dl>' +
      (english
        ? '<div class="bhw-triage-assessment__block"><span class="bhw-triage-assessment__label">English translation</span>' +
          '<p class="bhw-triage-assessment__text">' + escapeHtml(english) + '</p></div>'
        : '') +
      '<div class="bhw-triage-assessment__block"><span class="bhw-triage-assessment__label">Detected symptoms</span>' +
        renderListItems(symptoms, 'No symptoms detected.') +
      '</div>' +
      '<div class="bhw-triage-assessment__block"><span class="bhw-triage-assessment__label">Possible conditions</span>' +
        renderListItems(conditions, 'No conditions suggested.') +
      '</div>' +
      (recommendations.length
        ? '<div class="bhw-triage-assessment__block"><span class="bhw-triage-assessment__label">Recommendations</span>' +
          renderListItems(recommendations, '') + '</div>'
        : '') +
      renderPipelineSteps(pipeline.steps || []) +
      (pipeline.summary
        ? '<p class="bhw-triage-assessment__text bhw-triage-assessment__summary">' + escapeHtml(pipeline.summary) + '</p>'
        : '');

    assessmentSection.hidden = false;
  }

  function scrollVerifyStatusIntoView() {
    if (verifyStatusEl && verifyStatusEl.textContent && typeof verifyStatusEl.scrollIntoView === 'function') {
      verifyStatusEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  function applyRoutingMode(routing) {
    currentRouting = routing || null;
    isEmergency = routing && routing.tier === 'emergency';
    isUrgent = routing && routing.tier === 'urgent';

    if (emergencyBanner) emergencyBanner.style.display = isEmergency ? 'block' : 'none';
    if (emergencyOverlay) {
      emergencyOverlay.hidden = !isEmergency;
      emergencyOverlay.setAttribute('aria-hidden', isEmergency ? 'false' : 'true');
    }
    if (slotWrap) slotWrap.style.display = isEmergency ? 'none' : '';
    if (consentWrap) consentWrap.style.display = isEmergency ? 'none' : '';
    if (consentSection) consentSection.hidden = isEmergency;
    if (bookingSection) {
      bookingSection.hidden = isEmergency;
      bookingSection.style.display = isEmergency ? 'none' : '';
    }
    if (slotEl) slotEl.classList.toggle('bhw-triage-slot--priority', isUrgent);

    var consent = document.getElementById('bhwTeleConsent');
    if (!submitBtn || !slotEl) return;

    if (isEmergency) {
      slotEl.removeAttribute('required');
      if (consent) consent.checked = false;
      submitBtn.textContent = 'Generate Emergency Referral';
      submitBtn.classList.remove('bhw-triage-btn--primary');
      submitBtn.classList.add('bhw-triage-btn--emergency');
    } else {
      slotEl.setAttribute('required', 'required');
      submitBtn.textContent = isUrgent ? 'Book Priority Appointment' : 'Submit Triage & Book';
      submitBtn.classList.add('bhw-triage-btn--primary');
      submitBtn.classList.remove('bhw-triage-btn--emergency');
    }

    if (routing && routing.message && schedulingNoteEl) {
      schedulingNoteEl.textContent = routing.message;
    }

    if (!isEmergency && providerEl && providerEl.value) {
      loadSlots();
    }
  }

  function setEmergencyMode(on) {
    applyRoutingMode(on
      ? { tier: 'emergency', mode: 'emergency_referral', allow_booking: false, message: 'Emergency — referral only.' }
      : { tier: 'non_urgent', mode: 'standard_booking', allow_booking: true });
  }

  function applyAssessment(r) {
    if (r && r.success && r.assessment) {
      var routing = r.routing || null;
      var patientId = getSelectedPatientId();
      storeAssessmentToken(r.assessment_token || '', patientId);
      lastAssessedComplaint = getConcernText();
      cachedAssessmentResult = r;
      renderAssessmentPanel(r);
      applyRoutingMode(routing || (r.is_emergency
        ? { tier: 'emergency', mode: 'emergency_referral', allow_booking: false }
        : { tier: 'non_urgent', mode: 'standard_booking', allow_booking: true }));
      if (!r.is_emergency && bookingSection) {
        bookingSection.hidden = false;
        bookingSection.style.display = '';
      }
      if (!r.is_emergency && consentSection) {
        consentSection.hidden = false;
      }
      setVerifyStatus(triageStatusMessage(routing, !!r.is_emergency), 'ok');
      scrollVerifyStatusIntoView();
    } else {
      var message = (r && r.message)
        || (r && r.error)
        || ((r && !r.success && r.status === 403)
          ? 'Session expired. Please refresh the page and sign in again.'
          : '')
        || 'Triage could not be completed. Select a patient, enter the chief complaint, then click Run.';
      clearAssessmentState();
      setVerifyStatus(message, 'error');
      forceHideLoader();
      scrollVerifyStatusIntoView();
    }
    return r;
  }

  function formatDisplayDate(ymd) {
    if (!ymd) return '';
    var parts = ymd.split('-');
    if (parts.length !== 3) return ymd;
    var dt = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    if (isNaN(dt.getTime())) return ymd;
    return dt.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  }

  function updateProviderHint(providerId) {
    if (!providerHintEl) return;
    var meta = providerMeta[providerId];
    if (!meta) {
      providerHintEl.textContent = '';
      providerHintEl.hidden = true;
      return;
    }
    if (!meta.active_days || !meta.active_days.length) {
      providerHintEl.textContent = 'This provider has not published a schedule yet.';
      providerHintEl.hidden = false;
      return;
    }
    var text = 'Available: ' + (meta.active_days_label || meta.active_days.join(', '));
    if (meta.next_available_date) {
      text += ' · Next open date: ' + formatDisplayDate(meta.next_available_date);
    }
    providerHintEl.textContent = text;
    providerHintEl.hidden = false;
  }

  function updateSlotHint(message, tone) {
    if (!slotHintEl) return;
    if (!message) {
      slotHintEl.textContent = '';
      slotHintEl.hidden = true;
      slotHintEl.className = 'bhw-triage-hint';
      return;
    }
    slotHintEl.textContent = message;
    slotHintEl.hidden = false;
    slotHintEl.className = 'bhw-triage-hint' + (tone ? ' bhw-triage-hint--' + tone : '');
  }

  function summarizeSlots(slots) {
    if (!slots || !slots.length) return '';
    var dates = {};
    slots.forEach(function (s) {
      if (s.slot_date) dates[s.slot_date] = true;
    });
    var dateKeys = Object.keys(dates).sort();
    if (dateKeys.length === 1) {
      return slots.length + ' open slot' + (slots.length === 1 ? '' : 's') + ' on ' + formatDisplayDate(dateKeys[0]) + '.';
    }
    return slots.length + ' open slots across ' + dateKeys.length + ' days (next 28 days).';
  }

  function renderSlotOptions(slots, response) {
    slotEl.innerHTML = '';
    if (!(slots || []).length) {
      var emptyLabel = 'No slots available';
      if (response && response.notice) emptyLabel = response.notice;
      slotEl.innerHTML = '<option value="">' + emptyLabel + '</option>';
      updateSlotHint(response && response.notice ? response.notice : 'Try another provider.', 'warn');
      if (schedulingNoteEl) {
        schedulingNoteEl.textContent = response && response.notice
          ? response.notice
          : 'Slots sync automatically from the provider schedule.';
      }
      return;
    }

    slots.forEach(function (s) {
      var o = document.createElement('option');
      o.value = s.id;
      o.textContent = s.label;
      slotEl.appendChild(o);
    });

    updateSlotHint(summarizeSlots(slots), 'ok');
    if (schedulingNoteEl) {
      schedulingNoteEl.textContent = summarizeSlots(slots);
    }
  }

  function loadSlots() {
    if (!providerEl || !slotEl) return Promise.resolve();
    if (!providerEl.value) {
      slotEl.innerHTML = '<option value="">Select provider first…</option>';
      updateSlotHint('');
      return Promise.resolve();
    }

    updateProviderHint(providerEl.value);
    slotEl.innerHTML = '<option value="">Loading slots…</option>';
    updateSlotHint('Loading open slots from provider schedule…', 'info');

    return BhwPortal.get('appointments.php', {
      action: 'slots',
      provider_id: providerEl.value,
      range_days: isUrgent ? 7 : 28,
      priority: isUrgent ? 'urgent' : 'standard',
    }).then(function (r) {
      if (r.success === false) {
        slotEl.innerHTML = '<option value="">Could not load slots</option>';
        updateSlotHint(r.message || 'Could not load slots.', 'warn');
        return;
      }
      renderSlotOptions(r.slots || [], r);
    }).catch(function () {
      slotEl.innerHTML = '<option value="">Could not load slots</option>';
      updateSlotHint('Could not reach the scheduling service.', 'warn');
    });
  }

  function onProviderChange() {
    if (!providerEl.value) {
      slotEl.innerHTML = '<option value="">Select provider first…</option>';
      updateSlotHint('');
      updateProviderHint('');
      return;
    }
    loadSlots();
  }

  function runNlpAssessment(forceRun) {
    prepareComplaintForSubmit();
    var patientId = validatePatientSelected(true);
    if (!patientId) {
      return Promise.resolve(null);
    }

    var validation = validateChiefComplaint({ showErrors: true });
    if (!validation.ok) {
      if (nlpInputEl && typeof nlpInputEl.focus === 'function') {
        nlpInputEl.focus();
      }
      setVerifyStatus(validation.message, 'error');
      return Promise.resolve(null);
    }

    var concern = validation.trimmed || getConcernText();
    if (!forceRun && canReuseAssessment(concern, patientId)) {
      return Promise.resolve(cachedAssessmentResult);
    }

    setVerifyLoading(true);
    return BhwPortal.post('triage.php', {
      action: 'assess',
      patient_id: patientId,
      chief_complaint: concern,
    }).then(function (res) {
      if (res && res.success) {
        lastAssessedComplaint = concern;
        assessmentPatientId = patientId;
        cachedAssessmentResult = res;
      }
      return res;
    }).catch(function () {
      return {
        success: false,
        message: 'Network error while contacting the triage service.',
      };
    }).finally(function () {
      setVerifyLoading(false);
      forceHideLoader();
      if (verifyBtn) verifyBtn.disabled = false;
      if (verifyBtnLabel) verifyBtnLabel.textContent = 'Run';
    });
  }

  function handleVerifyClick() {
    runNlpAssessment().then(function (res) {
      if (!res) return;
      applyAssessment(res);
    });
  }

  BhwPortal.loadPatients(patientEl).then(function () {
    if (pre && patientEl) patientEl.value = String(pre);
  });

  BhwPortal.get('appointments.php', { action: 'providers' }).then(function (r) {
    if (!providerEl) return;
    (r.providers || []).forEach(function (p) {
      providerMeta[String(p.id)] = p;
      var o = document.createElement('option');
      o.value = p.id;
      o.textContent = 'Dr. ' + p.last_name + ', ' + p.first_name;
      providerEl.appendChild(o);
    });
    if (providerEl.options.length > 1) {
      providerEl.selectedIndex = 1;
      onProviderChange();
    }
  });

  if (providerEl) providerEl.addEventListener('change', onProviderChange);

  if (patientEl) {
    patientEl.addEventListener('change', function () {
      clearAssessmentState();
    });
  }

  if (verifyBtn) {
    verifyBtn.addEventListener('click', handleVerifyClick);
  }

  var emergencyAckBtn = document.getElementById('bhwEmergencyAckBtn');
  if (emergencyAckBtn && emergencyOverlay) {
    emergencyAckBtn.addEventListener('click', function () {
      emergencyOverlay.hidden = true;
      emergencyOverlay.setAttribute('aria-hidden', 'true');
    });
  }

  if (nlpInputEl) {
    nlpInputEl.addEventListener('input', function () {
      enforceComplaintLimit();
      autoResizeComplaintInput();
      updateComplaintCounter();

      var validation = validateChiefComplaint({ showErrors: false });
      if (validation.atLimit) {
        setComplaintError(COMPLAINT_MSG_LIMIT);
      } else if (validation.ok) {
        setComplaintError('');
      }

      if (verifyStatusEl && verifyStatusEl.textContent) {
        setVerifyStatus('');
      }
      if (assessmentToken) {
        clearAssessmentState();
      }
    });

    nlpInputEl.addEventListener('paste', function () {
      requestAnimationFrame(function () {
        enforceComplaintLimit();
        autoResizeComplaintInput();
        updateComplaintCounter();
      });
    });

    nlpInputEl.addEventListener('blur', function () {
      prepareComplaintForSubmit();
      validateChiefComplaint({ showErrors: true });
    });

    autoResizeComplaintInput();
    updateComplaintCounter();
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (submitBtn) submitBtn.disabled = true;

    var patientId = validatePatientSelected(true);
    if (!patientId) {
      if (submitBtn) submitBtn.disabled = false;
      return;
    }

    runNlpAssessment(false).then(function (assessRes) {
      if (!assessRes || !assessRes.success || !assessRes.assessment) {
        applyAssessment(assessRes || { success: false, message: 'Run triage before submitting.' });
        if (submitBtn) submitBtn.disabled = false;
        return;
      }
      applyAssessment(assessRes);
      var emergency = !!assessRes.is_emergency;
      var consent = document.getElementById('bhwTeleConsent');
      if (!emergency && consent && !consent.checked) {
        BhwPortal.toast('Teleconsult consent is required before booking.', false);
        if (submitBtn) submitBtn.disabled = false;
        return;
      }

      var fd = new FormData(form);
      fd.append('action', 'submit');
      if (assessmentToken) {
        fd.append('assessment_token', assessmentToken);
      }
      if (emergency) {
        fd.delete('slot_id');
        fd.append('slot_id', '0');
        fd.delete('teleconsult_consent');
      }

      showFormalLoader({
        preset: emergency ? 'submit' : 'booking',
        status: emergency ? 'Submitting emergency referral…' : 'Submitting triage & booking…',
        substatus: emergency
          ? 'Creating hospital referral record…'
          : 'Saving triage and reserving appointment slot…',
      });

      return BhwPortal.post('triage.php', fd).then(function (res) {
        if (res.emergency) {
          BhwPortal.showFeedback({
            type: 'success',
            title: 'Emergency Referral Created',
            message: res.message || 'Patient referred to hospital. Teleconsult was not booked.',
            primary: { label: 'View Referrals', href: res.redirect || '../referral/status.php' },
          });
          setTimeout(function () {
            location.href = res.redirect || '../referral/status.php';
          }, 2500);
          return;
        }
        BhwPortal.toast(res.message, res.success);
        if (res.success) {
          location.href = '../consultations/index.php?filter=active';
        }
      }).finally(function () {
        hideFormalLoader();
        forceHideLoader();
        if (submitBtn) submitBtn.disabled = false;
      });
    });
  });

  if (showPreview && verifyBtn && pre && getConcernText()) handleVerifyClick();
})();
