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
  var lastFocus = null;
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

  function renderSlotOptions(options) {
    if (!slotsList || !slotsWrap) return;
    slotsList.innerHTML = '';
    slotsWrap.hidden = false;

    if (!options || !options.length) {
      setSlotsStatus('No video slots left today. Contact the health office or try again tomorrow. If symptoms worsen, go to the ER.', true);
      return;
    }

    setSlotsStatus('');
    options.forEach(function (opt) {
      var card = document.createElement('div');
      card.className = 'mc-urgency-slot-card';
      card.setAttribute('role', 'listitem');

      var meta = document.createElement('div');
      meta.className = 'mc-urgency-slot-card__meta';

      var name = document.createElement('strong');
      name.className = 'mc-urgency-slot-card__name';
      name.textContent = opt.provider_name || 'Doctor';

      var time = document.createElement('span');
      time.className = 'mc-urgency-slot-card__time';
      time.textContent = 'Earliest: ' + (opt.time_label || opt.range_label || '—');

      var sub = document.createElement('span');
      sub.className = 'mc-urgency-slot-card__sub';
      sub.textContent = 'Today · Video · ' + (opt.range_label || '');

      meta.appendChild(name);
      meta.appendChild(time);
      meta.appendChild(sub);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mc-urgency-slot-card__btn';
      btn.textContent = 'Book this slot';
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
    setSlotsStatus('Loading soonest doctor times…');

    fetch(base() + '/app/api/patient/urgent_earliest_slots.php?_=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-MC-No-Loader': '1' },
    })
      .then(function (res) { return res.json().catch(function () { return null; }); })
      .then(function (data) {
        if (!data || !data.success) {
          setSlotsStatus((data && data.message) || 'Could not load doctor times. Use “Choose another time”.', true);
          return;
        }
        var options = (data.data && data.data.options) || data.options || [];
        renderSlotOptions(options);
      })
      .catch(function () {
        setSlotsStatus('Network error loading slots. Use “Choose another time”.', true);
      });
  }

  function bookSlot(slotId, providerName, timeLabel) {
    if (bookingInFlight || !slotId) return;
    var complaint = (urgentCtx.complaint || '').trim();
    if (!complaint) {
      setSlotsStatus('Missing health concern. Close and submit again, or use Choose another time.', true);
      return;
    }

    var confirmMsg = 'Book video with ' + (providerName || 'this doctor') + ' at ' + (timeLabel || 'the selected time') + '?';
    if (!window.confirm(confirmMsg)) {
      return;
    }

    bookingInFlight = true;
    setSlotsStatus('Booking…');
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
          setSlotsStatus((data && data.message) || 'Could not book. Try another doctor or Choose another time.', true);
          if (slotsList) {
            slotsList.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
          }
          loadEarliestSlots();
          return;
        }

        if (data.emergency === true || (data.data && data.data.emergency)) {
          setSlotsStatus(data.message || 'Emergency care required — video booking is not available.', true);
          return;
        }

        var booked = data.booked !== false && !(data.awaiting_provider_review === true);
        if (data.data && typeof data.data.booked !== 'undefined') {
          booked = data.data.booked !== false && !data.data.awaiting_provider_review;
        }

        if (!booked) {
          setSlotsStatus(data.message || 'Could not complete booking.', true);
          if (slotsList) {
            slotsList.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
          }
          return;
        }

        setSlotsStatus(data.message || 'Appointment booked. Redirecting…');
        setTimeout(function () {
          window.location.href = base() + '/views/patient/consultations.php';
        }, 1200);
      })
      .catch(function () {
        bookingInFlight = false;
        setSlotsStatus('Network error. Please try again.', true);
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

  function open(opts) {
    if (!els()) return;
    opts = opts || {};
    lastFocus = document.activeElement;
    bookingInFlight = false;

    var kind = normalizeKind(opts.kind);
    var triageResult = opts.mode === 'triage_result';

    modal.classList.toggle('is-urgent', kind === 'urgent');
    modal.classList.toggle('is-emergency', kind === 'emergency');
    modal.classList.toggle('is-non-urgent', kind === 'non_urgent');
    setIcon(kind);

    if (eyebrowEl) {
      if (kind === 'non_urgent') eyebrowEl.textContent = 'Non-urgent';
      else if (kind === 'urgent') eyebrowEl.textContent = 'Urgent';
      else eyebrowEl.textContent = 'Emergency';
    }
    if (titleEl) {
      titleEl.textContent = opts.title
        || (kind === 'non_urgent'
          ? 'Routine Care Recommended'
          : (kind === 'urgent'
            ? 'Urgent Medical Attention Recommended'
            : 'Emergency Symptoms Detected'));
    }
    if (msgEl) {
      msgEl.textContent = opts.message
        || (kind === 'non_urgent'
          ? 'Triage result: Non-Urgent. You may add optional supporting evidence, then submit for provider review.'
          : (kind === 'urgent'
            ? (triageResult
              ? 'Based on the symptoms you provided, your condition may require prompt medical attention.'
              : 'Your symptoms may need prompt care. Choose the soonest available doctor below.')
            : 'Based on the symptoms you entered, your condition may be a medical emergency. Please seek immediate medical attention at the nearest hospital or emergency department.'));
    }

    if (kind === 'emergency') {
      hideSlots();
      setSteps([
        'Call local emergency services if needed',
        'Go to the nearest hospital or ER',
        'Do not wait for online care tips or a video slot',
      ]);
      if (primaryBtn) {
        primaryBtn.hidden = true;
        primaryBtn.removeAttribute('href');
      }
    } else if (kind === 'non_urgent') {
      hideSlots();
      setSteps([
        'Supporting evidence is optional',
        'Submit your chief complaint for provider review',
        'Seek urgent or emergency care if symptoms worsen',
      ]);
      if (primaryBtn) {
        primaryBtn.hidden = true;
        primaryBtn.removeAttribute('href');
      }
    } else if (triageResult) {
      hideSlots();
      setSteps([
        'You may add optional supporting evidence before submitting',
        'After submit, book the earliest available consultation',
        'Seek ER care if symptoms suddenly worsen',
      ]);
      if (primaryBtn) {
        primaryBtn.hidden = true;
        primaryBtn.removeAttribute('href');
      }
    } else {
      setSteps([
        'Pick a doctor’s earliest open time today',
        'Confirm to book the video visit',
        'Seek ER care if symptoms suddenly worsen',
      ]);
      urgentCtx = {
        complaint: String(opts.complaint || '').trim(),
        triageId: parseInt(opts.triageId, 10) || 0,
        bookUrl: opts.bookUrl
          || (base() + '/views/patient/triage.php'),
      };
      if (primaryBtn) {
        primaryBtn.hidden = false;
        primaryBtn.textContent = 'Choose another time';
        primaryBtn.href = urgentCtx.bookUrl;
      }
      loadEarliestSlots();
    }

    modal.hidden = false;
    document.body.classList.add('mc-urgency-modal-open');
    var closeBtn = modal.querySelector('[data-mc-urgency-close]');
    if (closeBtn) closeBtn.focus();
  }

  function close() {
    if (!els()) return;
    if (bookingInFlight) return;
    modal.hidden = true;
    document.body.classList.remove('mc-urgency-modal-open');
    hideSlots();
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus(); } catch (_) { /* ignore */ }
    }
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;

    if (t.closest('[data-mc-urgency-close]')) {
      close();
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

  window.mcPatientUrgencyModal = {
    showEmergency: function (message) {
      open({ kind: 'emergency', message: message || '' });
    },
    showUrgent: function (message, bookUrl, extra) {
      extra = extra || {};
      open({
        kind: 'urgent',
        message: message || '',
        bookUrl: bookUrl || '',
        complaint: extra.complaint || '',
        triageId: extra.triageId || 0,
      });
    },
    showNonUrgent: function (message) {
      open({ kind: 'non_urgent', mode: 'triage_result', message: message || '' });
    },
    showTriageResult: function (urgency, message) {
      var kind = normalizeKind(urgency);
      if (kind === 'non_urgent') {
        open({ kind: 'non_urgent', mode: 'triage_result', message: message || '' });
        return;
      }
      if (kind === 'urgent') {
        open({ kind: 'urgent', mode: 'triage_result', message: message || '' });
        return;
      }
      open({ kind: 'emergency', mode: 'triage_result', message: message || '' });
    },
    close: close,
  };
})(window, document);
