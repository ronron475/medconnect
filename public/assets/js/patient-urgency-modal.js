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
  var lastOpts = null;
  var langSelect = null;
  var lastFocus = null;

  function i18n(key, vars) {
    if (window.McPatientTriageI18n && typeof window.McPatientTriageI18n.t === 'function') {
      return window.McPatientTriageI18n.t(key, vars);
    }
    return key;
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

  function renderSlotOptions(options) {
    if (!slotsList || !slotsWrap) return;
    slotsList.innerHTML = '';
    slotsWrap.hidden = false;

    if (!options || !options.length) {
      setSlotsStatus(i18n('slots_empty'), true);
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
      name.textContent = opt.provider_name || i18n('doctor');

      var time = document.createElement('span');
      time.className = 'mc-urgency-slot-card__time';
      time.textContent = i18n('slots_earliest', { time: opt.time_label || opt.range_label || '—' });

      var sub = document.createElement('span');
      sub.className = 'mc-urgency-slot-card__sub';
      sub.textContent = i18n('slots_today_video', { range: opt.range_label || '' });

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

  function open(opts) {
    if (!els()) return;
    opts = opts || {};
    lastOpts = opts;
    lastFocus = document.activeElement;
    bookingInFlight = false;

    var kind = normalizeKind(opts.kind);
    var triageResult = opts.mode === 'triage_result';

    modal.classList.toggle('is-urgent', kind === 'urgent');
    modal.classList.toggle('is-emergency', kind === 'emergency');
    modal.classList.toggle('is-non-urgent', kind === 'non_urgent');
    setIcon(kind);

    if (langSelect && window.McPatientTriageI18n) {
      langSelect.value = window.McPatientTriageI18n.current();
    }

    if (eyebrowEl) {
      if (kind === 'non_urgent') eyebrowEl.textContent = i18n('eyebrow_non_urgent');
      else if (kind === 'urgent') eyebrowEl.textContent = i18n('eyebrow_urgent');
      else eyebrowEl.textContent = i18n('eyebrow_emergency');
    }
    if (titleEl) {
      titleEl.textContent = opts.title
        || (kind === 'non_urgent'
          ? i18n('title_non_urgent')
          : (kind === 'urgent' ? i18n('title_urgent') : i18n('title_emergency')));
    }
    if (msgEl) {
      var defaultMsg = kind === 'non_urgent'
        ? i18n('msg_non_urgent')
        : (kind === 'urgent'
          ? i18n('msg_urgent')
          : i18n('msg_emergency'));
      msgEl.textContent = opts.useCustomMessage && opts.message ? opts.message : defaultMsg;
    }

    var closeBtn = modal.querySelector('[data-mc-urgency-close]');
    if (closeBtn) closeBtn.textContent = i18n('i_understand');

    if (kind === 'emergency') {
      hideSlots();
      setSteps([i18n('step_em_1'), i18n('step_em_2'), i18n('step_em_3')]);
      if (primaryBtn) {
        primaryBtn.hidden = true;
        primaryBtn.removeAttribute('href');
      }
    } else if (kind === 'non_urgent') {
      hideSlots();
      setSteps([i18n('step_nu_1'), i18n('step_nu_2'), i18n('step_nu_3')]);
      if (primaryBtn) {
        primaryBtn.hidden = true;
        primaryBtn.removeAttribute('href');
      }
    } else if (triageResult) {
      hideSlots();
      setSteps([i18n('step_urg_triage_1'), i18n('step_urg_triage_2'), i18n('step_urg_triage_3')]);
      if (primaryBtn) {
        primaryBtn.hidden = true;
        primaryBtn.removeAttribute('href');
      }
    } else {
      setSteps([i18n('step_urg_book_1'), i18n('step_urg_book_2'), i18n('step_urg_book_3')]);
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
    document.body.classList.add('mc-urgency-modal-open');
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

  window.addEventListener('medconnect:patient-ui-lang', function () {
    if (modal && !modal.hidden && lastOpts) {
      open(lastOpts);
    }
  });

  window.mcPatientUrgencyModal = {
    showEmergency: function (message) {
      open({ kind: 'emergency', mode: 'triage_result', useCustomMessage: !!message, message: message || '' });
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
    showTriageResult: function (urgency, message) {
      var kind = normalizeKind(urgency);
      open({
        kind: kind,
        mode: 'triage_result',
        useCustomMessage: false,
        message: message || '',
      });
    },
    close: close,
  };
})(window, document);
