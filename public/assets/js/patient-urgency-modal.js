/**
 * Patient urgency modal (emergency / urgent / non-urgent) after symptom or triage submit.
 * Urgent booking mode: lists each doctor's earliest open slot today and allows confirm-to-book.
 */
(function (window, document) {
  'use strict';

  var WARNING_ICON_SVG = '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
    + '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
    + '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
  var SUCCESS_ICON_SVG = '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
    + '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>'
    + '<polyline points="22 4 12 14.01 9 11.01"/></svg>';

  var modal = null;
  var titleEl = null;
  var msgEl = null;
  var eyebrowEl = null;
  var iconEl = null;
  var stepsEl = null;
  var primaryBtn = null;
  var slotsWrap = null;
  var slotsList = null;
  var slotsStatus = null;
  var continueConsultBtn = null;
  var continueEl = null;
  var safetyEl = null;
  var facilityWrap = null;
  var facilityHeading = null;
  var facilityStatus = null;
  var facilityCard = null;
  var facilityName = null;
  var facilityType = null;
  var facilityAddress = null;
  var facilityDistance = null;
  var facilityContact = null;
  var facilityOpen = null;
  var facilityDirectory = null;
  var lastOpts = null;
  var langSelect = null;
  var lastFocus = null;

  var I18N_FALLBACKS = {
    i_understand: 'I understand',
    choose_another_time: 'Choose another time',
    eyebrow_non_urgent: 'NON-URGENT',
    eyebrow_urgent: 'URGENT',
    eyebrow_emergency: 'EMERGENCY',
    title_non_urgent: 'Regular Check-up Recommended',
    title_urgent: 'Prompt Medical Attention Recommended',
    title_urgent_consult: 'Urgent Consultation Recommended',
    title_emergency: 'Seek Emergency Care Immediately',
    ai_assessment_line: 'AI Assessment: {level}',
    instruction_non_urgent: 'Review the assessment, then tap “Submit Patient Complaint” to continue.',
    instruction_urgent: 'Review the assessment, then tap “Submit Patient Complaint” to continue.',
    instruction_urgent_book: 'Choose a doctor with an open slot today to book your visit.',
    instruction_emergency: 'Go to the nearest hospital or emergency department now.',
    safety_non_urgent: 'Seek urgent care if symptoms suddenly worsen.',
    safety_urgent: 'Seek ER care if symptoms suddenly worsen.',
    safety_emergency: 'Do not wait for online care tips or a video consultation.',
    msg_emergency: 'AI Assessment: EMERGENCY',
    msg_urgent: 'AI Assessment: URGENT',
    msg_non_urgent: 'AI Assessment: NON-URGENT',
    slots_heading: 'Doctors available today',
    slots_heading_recommended: 'Recommended — Earliest Available',
    slots_heading_others: 'Other Available Doctors',
    slots_loading: 'Loading doctors with open slots today…',
    slots_empty: 'No video slots left today. Contact the health office or try again tomorrow. If symptoms worsen, go to the ER.',
    slots_load_fail: 'Could not load doctor times. Use “Choose another time”.',
    slots_network: 'Network error loading slots. Use “Choose another time”.',
    slots_earliest: 'Earliest: {time}',
    slots_today: 'Today, {time}',
    slots_today_video: 'Today · Video · {range}',
    slots_book: 'Book',
    slots_missing_complaint: 'Missing health concern. Close and submit again, or use Choose another time.',
    slots_confirm: 'Book video with {name} at {time}?',
    slots_booking: 'Booking…',
    slots_book_fail: 'Could not book. Try another doctor or Choose another time.',
    slots_emergency: 'Emergency care required — video booking is not available.',
    slots_incomplete: 'Could not complete booking.',
    slots_booked: 'Appointment booked. Redirecting…',
    slots_book_network: 'Network error. Please try again.',
    doctor: 'Doctor',
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
  var urgentCtx = { complaint: '', triageId: 0, bookUrl: '' };
  var bookingInFlight = false;

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

  function els() {
    if (!modal) {
      modal = document.getElementById('mcPatientUrgencyModal');
      titleEl = document.getElementById('mcPatientUrgencyTitle');
      msgEl = document.getElementById('mcPatientUrgencyMessage');
      eyebrowEl = document.getElementById('mcPatientUrgencyEyebrow');
      stepsEl = document.getElementById('mcPatientUrgencySteps');
      iconEl = document.getElementById('mcPatientUrgencyIcon');
      primaryBtn = document.getElementById('mcPatientUrgencyPrimary');
      slotsWrap = document.getElementById('mcPatientUrgencySlots');
      slotsList = document.getElementById('mcPatientUrgencySlotsList');
      slotsStatus = document.getElementById('mcPatientUrgencySlotsStatus');
      continueEl = document.getElementById('mcPatientUrgencyContinue');
      safetyEl = document.getElementById('mcPatientUrgencySafety');
      continueConsultBtn = document.getElementById('mcPatientUrgencyContinueConsult');
      facilityWrap = document.getElementById('mcPatientUrgencyFacility');
      facilityHeading = document.getElementById('mcPatientUrgencyFacilityHeading');
      facilityStatus = document.getElementById('mcPatientUrgencyFacilityStatus');
      facilityCard = document.getElementById('mcPatientUrgencyFacilityCard');
      facilityName = document.getElementById('mcPatientUrgencyFacilityName');
      facilityType = document.getElementById('mcPatientUrgencyFacilityType');
      facilityAddress = document.getElementById('mcPatientUrgencyFacilityAddress');
      facilityDistance = document.getElementById('mcPatientUrgencyFacilityDistance');
      facilityContact = document.getElementById('mcPatientUrgencyFacilityContact');
      facilityOpen = document.getElementById('mcPatientUrgencyFacilityOpen');
      facilityDirectory = document.getElementById('mcPatientUrgencyFacilityDirectory');
      langSelect = document.getElementById('mcPatientUrgencyLang');
      if (langSelect && window.McPatientTriageI18n && typeof window.McPatientTriageI18n.bindSelector === 'function') {
        window.McPatientTriageI18n.bindSelector(langSelect);
      }
    }
    return !!modal;
  }

  function setSteps(items) {
    if (!stepsEl) return;
    stepsEl.innerHTML = '';
    (items || []).forEach(function (text) {
      var li = document.createElement('li');
      li.textContent = text;
      stepsEl.appendChild(li);
    });
    stepsEl.hidden = !items || items.length === 0;
  }

  function setSlotsStatus(text, isError) {
    if (!slotsStatus) return;
    if (!text) {
      slotsStatus.hidden = true;
      slotsStatus.textContent = '';
      return;
    }
    slotsStatus.hidden = false;
    slotsStatus.textContent = text;
    slotsStatus.classList.toggle('is-error', !!isError);
  }

  function hideSlots() {
    if (slotsWrap) slotsWrap.hidden = true;
    if (slotsList) slotsList.innerHTML = '';
    setSlotsStatus('');
  }

  function hideFacility() {
    if (facilityWrap) facilityWrap.hidden = true;
    if (facilityStatus) {
      facilityStatus.hidden = true;
      facilityStatus.textContent = '';
    }
    if (facilityCard) facilityCard.hidden = true;
    if (facilityDirectory) {
      facilityDirectory.hidden = true;
      facilityDirectory.innerHTML = '';
    }
    if (continueConsultBtn) continueConsultBtn.hidden = true;
  }

  function renderFacility(payload) {
    if (!facilityWrap) return;
    payload = payload || {};
    var nearest = payload.facility || null;
    var directory = Array.isArray(payload.directory) ? payload.directory : [];
    var claimed = payload.claimed_nearest === true && nearest && payload.available === true;
    var locationOk = payload.location_available === true || claimed;

    facilityWrap.hidden = false;
    if (facilityHeading) {
      facilityHeading.textContent = claimed ? 'NEAREST HEALTH FACILITY' : 'REGISTERED HEALTH FACILITIES';
    }

    if (facilityStatus) {
      if (claimed) {
        facilityStatus.hidden = true;
        facilityStatus.textContent = '';
      } else if (!locationOk) {
        facilityStatus.hidden = false;
        facilityStatus.textContent = payload.message || 'Location unavailable';
      } else {
        facilityStatus.hidden = false;
        facilityStatus.textContent = payload.message
          || 'A registered facility directory is shown. Distance is only shown when coordinates are available.';
      }
    }

    if (facilityCard) {
      if (claimed && nearest) {
        facilityCard.hidden = false;
        if (facilityName) facilityName.textContent = nearest.name || '';
        if (facilityType) facilityType.textContent = nearest.type || '';
        if (facilityAddress) facilityAddress.textContent = nearest.address || '';
        if (facilityDistance) {
          facilityDistance.textContent = nearest.distance_label
            ? ('Distance: ' + nearest.distance_label)
            : '';
        }
        if (facilityContact) {
          facilityContact.textContent = nearest.contact ? ('Contact: ' + nearest.contact) : '';
        }
        if (facilityOpen) {
          facilityOpen.textContent = nearest.status ? ('Status: ' + nearest.status) : '';
        }
      } else {
        facilityCard.hidden = true;
      }
    }

    if (facilityDirectory) {
      facilityDirectory.innerHTML = '';
      if (!claimed && directory.length) {
        facilityDirectory.hidden = false;
        directory.slice(0, 8).forEach(function (item) {
          var li = document.createElement('li');
          var label = (item && item.name) ? item.name : 'Facility';
          if (item && item.type) label += ' (' + item.type + ')';
          if (item && item.address) label += ' — ' + item.address;
          li.textContent = label;
          facilityDirectory.appendChild(li);
        });
      } else {
        facilityDirectory.hidden = true;
      }
    }

    return claimed && nearest && nearest.maps_url ? nearest.maps_url : '';
  }

  function hasFacilityPayload(payload) {
    if (!payload || typeof payload !== 'object') return false;
    if (payload.claimed_nearest === true || payload.available === true) return true;
    if (payload.location_available === true) return true;
    if (payload.facility && typeof payload.facility === 'object') return true;
    if (Array.isArray(payload.directory) && payload.directory.length) return true;
    return Object.prototype.hasOwnProperty.call(payload, 'message');
  }

  function renderSlotOptions(options) {
    if (!slotsList || !slotsWrap) return;
    slotsList.innerHTML = '';
    slotsWrap.hidden = false;

    if (!options || !options.length) {
      setSlotsStatus(i18n('slots_empty'), true);
      return;
    }

    setSlotsStatus('');
    var heading = slotsWrap.querySelector('.mc-urgency-slots__heading');
    if (heading) heading.hidden = true;

    options.forEach(function (opt, idx) {
      var recommended = !!opt.recommended || idx === 0;
      if (idx === 0) {
        var recHead = document.createElement('p');
        recHead.className = 'mc-urgency-slots__heading mc-urgency-slots__heading--group';
        recHead.textContent = i18n('slots_heading_recommended');
        slotsList.appendChild(recHead);
      } else if (idx === 1) {
        var otherHead = document.createElement('p');
        otherHead.className = 'mc-urgency-slots__heading mc-urgency-slots__heading--group';
        otherHead.textContent = i18n('slots_heading_others');
        slotsList.appendChild(otherHead);
      }

      var card = document.createElement('div');
      card.className = 'mc-urgency-slot-card' + (recommended ? ' is-recommended' : '');
      card.setAttribute('role', 'listitem');

      var meta = document.createElement('div');
      meta.className = 'mc-urgency-slot-card__meta';

      var name = document.createElement('strong');
      name.className = 'mc-urgency-slot-card__name';
      name.textContent = opt.provider_name || i18n('doctor');

      var time = document.createElement('span');
      time.className = 'mc-urgency-slot-card__time';
      time.textContent = i18n('slots_today', { time: opt.time_label || opt.range_label || '—' });

      var sub = document.createElement('span');
      sub.className = 'mc-urgency-slot-card__sub';
      sub.textContent = i18n('slots_earliest', { time: opt.time_label || opt.range_label || '' });

      meta.appendChild(name);
      meta.appendChild(time);
      meta.appendChild(sub);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mc-urgency-slot-card__btn';
      btn.textContent = i18n('slots_book');
      btn.dataset.slotId = String(opt.slot_id || '');
      btn.dataset.providerName = String(opt.provider_name || '');
      btn.dataset.timeLabel = String(opt.time_label || '');

      card.appendChild(meta);
      card.appendChild(btn);
      slotsList.appendChild(card);
    });
  }

  function loadEarliestSlots() {
    if (!slotsWrap || !slotsList) return;
    slotsWrap.hidden = false;
    slotsList.innerHTML = '';
    setSlotsStatus(i18n('slots_loading'));

    fetch(base() + '/app/api/patient/urgent_earliest_slots.php?_=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-MC-No-Loader': '1' },
    })
      .then(function (res) { return res.json().catch(function () { return null; }); })
      .then(function (data) {
        if (!data || !data.success) {
          setSlotsStatus((data && data.message) || i18n('slots_load_fail'), true);
          return;
        }
        var options = (data.data && data.data.options) || data.options || [];
        renderSlotOptions(options);
      })
      .catch(function () {
        setSlotsStatus(i18n('slots_network'), true);
      });
  }

  function bookSlot(slotId, providerName, timeLabel) {
    if (bookingInFlight || !slotId) return;
    var complaint = (urgentCtx.complaint || '').trim();
    if (!complaint) {
      setSlotsStatus(i18n('slots_missing_complaint'), true);
      return;
    }

    var confirmMsg = i18n('slots_confirm', {
      name: providerName || i18n('doctor'),
      time: timeLabel || '',
    });
    if (!window.confirm(confirmMsg)) {
      return;
    }

    bookingInFlight = true;
    setSlotsStatus(i18n('slots_booking'));
    if (slotsList) {
      slotsList.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    }

    var fd = new FormData();
    fd.set('chief_complaint', complaint);
    fd.set('slot_id', String(slotId));
    fd.set('csrf_token', csrf());
    if (urgentCtx.triageId > 0) {
      fd.set('triage_id', String(urgentCtx.triageId));
    }

    fetch(base() + '/app/api/patient/submit_triage.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-MC-No-Loader': '1' },
    })
      .then(function (res) { return res.json().catch(function () { return null; }); })
      .then(function (data) {
        bookingInFlight = false;
        if (!data || !data.success) {
          setSlotsStatus((data && data.message) || i18n('slots_book_fail'), true);
          if (slotsList) {
            slotsList.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
          }
          loadEarliestSlots();
          return;
        }

        if (data.emergency === true || (data.data && data.data.emergency)) {
          setSlotsStatus(data.message || i18n('slots_emergency'), true);
          return;
        }

        var booked = data.booked !== false && !(data.awaiting_provider_review === true) && !(data.waiting_for_slot === true);
        if (data.data && typeof data.data.booked !== 'undefined') {
          booked = data.data.booked !== false && !data.data.awaiting_provider_review && !data.data.waiting_for_slot;
        }

        if (!booked) {
          setSlotsStatus(data.message || i18n('slots_incomplete'), true);
          if (slotsList) {
            slotsList.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
          }
          return;
        }

        setSlotsStatus(data.message || i18n('slots_booked'));
        setTimeout(function () {
          window.location.href = base() + '/views/patient/consultations.php';
        }, 1200);
      })
      .catch(function () {
        bookingInFlight = false;
        setSlotsStatus(i18n('slots_book_network'), true);
        if (slotsList) {
          slotsList.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
        }
      });
  }

  function normalizeKind(kind) {
    var raw = String(kind || 'emergency').trim().toLowerCase().replace(/_/g, '-');
    if (raw === 'non-urgent' || raw === 'nonurgent') return 'non_urgent';
    if (raw === 'urgent') return 'urgent';
    return 'emergency';
  }

  function setIcon(kind) {
    if (!iconEl) return;
    iconEl.innerHTML = kind === 'non_urgent' ? SUCCESS_ICON_SVG : WARNING_ICON_SVG;
  }

  function levelLabel(kind) {
    if (kind === 'non_urgent') return i18n('eyebrow_non_urgent');
    if (kind === 'urgent') return i18n('eyebrow_urgent');
    return i18n('eyebrow_emergency');
  }

  function setSafety(text) {
    if (!safetyEl) return;
    if (!text) {
      safetyEl.hidden = true;
      safetyEl.textContent = '';
      return;
    }
    safetyEl.hidden = false;
    safetyEl.textContent = text;
  }

  function setInstruction(text) {
    if (!continueEl) return;
    if (!text) {
      continueEl.hidden = true;
      continueEl.textContent = '';
      return;
    }
    continueEl.hidden = false;
    continueEl.textContent = text;
  }

  function open(opts) {
    try {
      if (!els()) return;
    } catch (err) {
      console.warn('Urgency modal failed to initialize', err);
      return;
    }
    opts = opts || {};
    lastOpts = opts;
    lastFocus = document.activeElement;
    bookingInFlight = false;
    hideFacility();
    setSteps([]);
    setSafety('');
    setInstruction('');
    var understandBtn = modal.querySelector('[data-mc-urgency-close]');
    if (understandBtn) understandBtn.hidden = false;

    var kind = normalizeKind(opts.kind);
    var triageResult = opts.mode === 'triage_result';
    var doctorReferral = opts.doctorReferral === true;

    modal.classList.toggle('is-urgent', kind === 'urgent');
    modal.classList.toggle('is-emergency', kind === 'emergency');
    modal.classList.toggle('is-non-urgent', kind === 'non_urgent');
    setIcon(kind);

    if (langSelect && window.McPatientTriageI18n) {
      langSelect.value = window.McPatientTriageI18n.current();
    }

    var classification = levelLabel(kind);
    if (eyebrowEl) eyebrowEl.textContent = classification;

    if (titleEl) {
      if (opts.title) {
        titleEl.textContent = opts.title;
      } else if (kind === 'non_urgent') {
        titleEl.textContent = i18n('title_non_urgent');
      } else if (kind === 'urgent') {
        titleEl.textContent = triageResult ? i18n('title_urgent') : i18n('title_urgent_consult');
      } else {
        titleEl.textContent = i18n('title_emergency');
      }
    }

    if (msgEl) {
      if (doctorReferral && opts.useCustomMessage && opts.message) {
        msgEl.textContent = opts.message;
      } else {
        msgEl.textContent = i18n('ai_assessment_line', { level: classification });
      }
    }

    var closeBtn = modal.querySelector('[data-mc-urgency-close]');
    if (closeBtn) closeBtn.textContent = i18n('i_understand');

    if (kind === 'emergency') {
      hideSlots();
      if (!doctorReferral) {
        setInstruction(i18n('instruction_emergency'));
        setSafety(i18n('safety_emergency'));
      } else {
        setInstruction('');
        setSafety(i18n('safety_emergency'));
      }
      var facilityPayload = opts.facility || null;
      var showNearestHospital = doctorReferral || hasFacilityPayload(facilityPayload);
      if (showNearestHospital) {
        var mapsUrl = renderFacility(facilityPayload || {});
        if (closeBtn && closeBtn.id !== 'mcPatientUrgencyContinueConsult') {
          closeBtn.hidden = doctorReferral === true;
        }
        if (continueConsultBtn) continueConsultBtn.hidden = doctorReferral !== true;
        if (primaryBtn) {
          if (mapsUrl) {
            primaryBtn.hidden = false;
            primaryBtn.textContent = 'Get Directions';
            primaryBtn.href = mapsUrl;
            primaryBtn.target = '_blank';
            primaryBtn.rel = 'noopener noreferrer';
          } else {
            primaryBtn.hidden = true;
            primaryBtn.removeAttribute('href');
          }
        }
      } else {
        hideFacility();
        if (continueConsultBtn) continueConsultBtn.hidden = true;
        if (primaryBtn) {
          primaryBtn.hidden = true;
          primaryBtn.removeAttribute('href');
        }
      }
    } else if (kind === 'non_urgent') {
      hideSlots();
      setInstruction(i18n('instruction_non_urgent'));
      setSafety(i18n('safety_non_urgent'));
      if (primaryBtn) {
        primaryBtn.hidden = true;
        primaryBtn.removeAttribute('href');
      }
    } else if (triageResult) {
      hideSlots();
      setInstruction(i18n('instruction_urgent'));
      setSafety(i18n('safety_urgent'));
      if (primaryBtn) {
        primaryBtn.hidden = true;
        primaryBtn.removeAttribute('href');
      }
    } else {
      setInstruction(i18n('instruction_urgent_book'));
      setSafety(i18n('safety_urgent'));
      urgentCtx = {
        complaint: String(opts.complaint || '').trim(),
        triageId: parseInt(opts.triageId, 10) || 0,
        bookUrl: opts.bookUrl
          || (base() + '/views/patient/triage.php'),
      };
      if (primaryBtn) {
        primaryBtn.hidden = false;
        primaryBtn.textContent = i18n('choose_another_time');
        primaryBtn.href = urgentCtx.bookUrl;
      }
      loadEarliestSlots();
    }

    modal.hidden = false;
    modal.removeAttribute('hidden');
    document.body.classList.add('mc-urgency-modal-open');
    if (closeBtn) closeBtn.focus();
  }

  function close() {
    if (!els()) return;
    // Always allow dismiss so Start New Complaint / page controls are never trapped
    // under a leftover urgency backdrop (bookingInFlight only blocks Escape/book flow).
    bookingInFlight = false;
    modal.hidden = true;
    modal.setAttribute('hidden', '');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('mc-urgency-modal-open');
    hideSlots();
    hideFacility();
    setInstruction('');
    setSafety('');
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus(); } catch (_) { /* ignore */ }
    }
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;

    if (t.closest('[data-mc-urgency-close]')) {
      close();
      // Ensure page controls (e.g. Start New Complaint) receive clicks after dismiss.
      document.body.classList.remove('mc-urgency-modal-open', 'mc-nav-closing');
      return;
    }

    var bookBtn = t.closest('.mc-urgency-slot-card__btn');
    if (bookBtn && modal && !modal.hidden) {
      bookSlot(
        bookBtn.dataset.slotId,
        bookBtn.dataset.providerName,
        bookBtn.dataset.timeLabel
      );
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal && !modal.hidden && !bookingInFlight) {
      close();
    }
  });

  window.addEventListener('medconnect:patient-ui-lang', function () {
    if (modal && !modal.hidden && lastOpts) {
      open(lastOpts);
    }
  });

  window.mcPatientUrgencyModal = {
    showEmergency: function (message, extra) {
      extra = extra || {};
      open({
        kind: 'emergency',
        mode: 'triage_result',
        useCustomMessage: !!message,
        message: message || '',
        title: extra.title || '',
        doctorReferral: extra.doctorReferral === true,
        facility: extra.facility || null,
      });
    },
    showDoctorEmergencyReferral: function (opts) {
      opts = opts || {};
      open({
        kind: 'emergency',
        mode: 'triage_result',
        doctorReferral: true,
        useCustomMessage: true,
        title: opts.title || 'EMERGENCY — IMMEDIATE MEDICAL ATTENTION REQUIRED',
        message: opts.message
          || 'Your doctor has classified your condition as an EMERGENCY. Please seek immediate in-person medical attention.',
        facility: opts.facility || {},
      });
    },
    showUrgent: function (message, bookUrl, extra) {
      extra = extra || {};
      open({
        kind: 'urgent',
        message: message || '',
        useCustomMessage: false,
        bookUrl: bookUrl || '',
        complaint: extra.complaint || '',
        triageId: extra.triageId || 0,
      });
    },
    showNonUrgent: function (message) {
      open({ kind: 'non_urgent', mode: 'triage_result', useCustomMessage: false, message: message || '' });
    },
    showTriageResult: function (urgency, message, extra) {
      extra = extra || {};
      var kind = normalizeKind(urgency);
      open({
        kind: kind,
        mode: 'triage_result',
        useCustomMessage: !!message,
        message: message || '',
        doctorReferral: extra.doctorReferral === true,
        facility: extra.facility || null,
      });
      if (modal) {
        modal.hidden = false;
        modal.removeAttribute('hidden');
        document.body.classList.add('mc-urgency-modal-open');
      }
    },
    close: close,
  };
})(window, document);
