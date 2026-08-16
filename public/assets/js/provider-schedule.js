/**
 * Provider Schedule & Availability — daily video-consult editor
 * Only today is editable; other weekdays are locked (view only) after midnight.
 * No full-page loader on save — confirm modal only.
 */
(function () {
  'use strict';

  const SCHEDULE_TODAY = window.SCHEDULE_CONFIG?.today || '';
  const SCHEDULE_API = window.SCHEDULE_CONFIG?.api || '';
  const REMOVE_SLOT_API = window.SCHEDULE_CONFIG?.removeSlotApi || '';
  const RESCHEDULE_API = window.SCHEDULE_CONFIG?.rescheduleApi || '';
  const RESCHEDULE_SLOTS_API = window.SCHEDULE_CONFIG?.rescheduleSlotsApi || '';
  const LIVE_API = window.SCHEDULE_CONFIG?.liveApi || '';
  const LOGIN_URL = window.SCHEDULE_CONFIG?.loginUrl || '/';
  const LIVE_POLL_MS = 5000;

  const DURATION_OPTIONS = [
    { v: 15, l: '15 min' },
    { v: 30, l: '30 min' },
    { v: 45, l: '45 min' },
    { v: 60, l: '1 hour' },
  ];

  function timeToMinutes(t) {
    if (!t) return -1;
    const p = t.split(':');
    return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
  }

  function validateSessions(cards, requireAtLeastOne) {
    const errors = [];
    const ranges = [];

    if (requireAtLeastOne && !cards.length) {
      return ['Add at least one availability session.'];
    }
    if (!cards.length) return [];

    cards.forEach((card, idx) => {
      const label = 'Session ' + (idx + 1);
      const start = card.querySelector('.schedule-start')?.value || '';
      const end = card.querySelector('.schedule-end')?.value || '';
      const dur = parseInt(card.querySelector('.schedule-duration')?.value || '30', 10);

      if (!start || !end) {
        errors.push(label + ': start and end times are required.');
        return;
      }
      const sm = timeToMinutes(start);
      const em = timeToMinutes(end);
      if (em <= sm) {
        errors.push(label + ': end time must be later than start time.');
        return;
      }
      if (em - sm < dur) {
        errors.push(label + ': session must be at least as long as the slot length (' + dur + ' min).');
        return;
      }

      const key = start + '|' + end + '|' + dur;
      const dup = ranges.find((r) => r.key === key);
      if (dup) {
        errors.push(label + ': duplicate time range (same as Session ' + dup.num + ').');
        return;
      }

      ranges.push({ num: idx + 1, key, start: sm, end: em, label });
      card.classList.toggle('is-invalid', false);
    });

    ranges.sort((a, b) => a.start - b.start);
    for (let i = 1; i < ranges.length; i++) {
      if (ranges[i].start < ranges[i - 1].end) {
        errors.push('Sessions overlap between ' + ranges[i - 1].label + ' and ' + ranges[i].label + '.');
        break;
      }
    }

    return errors;
  }

  function showValidation(box, errors) {
    if (!box) return;
    if (!errors.length) {
      box.hidden = true;
      box.textContent = '';
      return;
    }
    box.hidden = false;
    box.innerHTML = '<strong>Please fix the following:</strong><ul>'
      + errors.map((e) => '<li>' + escapeHtml(e) + '</li>').join('')
      + '</ul>';
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function renumberSessions(list) {
    list.querySelectorAll('[data-session-card]').forEach((card, i) => {
      const num = card.querySelector('[data-session-num]');
      if (num) num.textContent = String(i + 1);
    });
  }

  function buildSessionCard() {
    const wrap = document.createElement('div');
    wrap.className = 'sched-session-card';
    wrap.setAttribute('data-session-card', '');
    wrap.setAttribute('data-session-id', '');

    const durOpts = DURATION_OPTIONS.map(
      (o) => '<option value="' + o.v + '">' + o.l + '</option>'
    ).join('');

    wrap.innerHTML =
      '<div class="sched-session-card__head">'
      + '<span class="sched-session-card__label">Session <span data-session-num>1</span></span>'
      + '<button type="button" class="sched-session-remove" data-remove-session title="Remove session" aria-label="Remove session">'
      + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Remove'
      + '</button></div>'
      + '<div class="sched-session-card__grid">'
      + '<div class="sched-session-field"><label>Start time</label><input type="time" class="sched-field schedule-start" value="09:00" required></div>'
      + '<div class="sched-session-field"><label>End time</label><input type="time" class="sched-field schedule-end" value="12:00" required></div>'
      + '<div class="sched-session-field"><label>Slot length</label><select class="sched-field schedule-duration">' + durOpts + '</select></div>'
      + '</div>';

    return wrap;
  }

  function collectSessions(dayBlock) {
    const list = dayBlock.querySelector('[data-sessions-list]');
    if (!list) return [];
    return Array.from(list.querySelectorAll('[data-session-card]')).map((card) => {
      const id = card.getAttribute('data-session-id');
      return {
        id: id ? parseInt(id, 10) : null,
        start_time: card.querySelector('.schedule-start')?.value || '',
        end_time: card.querySelector('.schedule-end')?.value || '',
        duration: parseInt(card.querySelector('.schedule-duration')?.value || '30', 10),
      };
    });
  }

  function confirmSave(dayName) {
    const modal = document.getElementById('schedConfirmModal');
    if (!modal) {
      return Promise.resolve(window.confirm(
        'Are you sure you want to save today\'s availability?'
      ));
    }

    const msg = modal.querySelector('#schedConfirmMessage');
    if (msg) {
      msg.textContent = 'Are you sure you want to save ' + (dayName || 'today')
        + '\'s availability? This will update open appointment slots.';
    }

    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');

    return new Promise((resolve) => {
      const finish = (ok) => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        modal.removeEventListener('click', onClick);
        document.removeEventListener('keydown', onKey);
        resolve(ok);
      };
      const onClick = (e) => {
        if (e.target.closest('[data-sched-confirm-yes]')) {
          finish(true);
          return;
        }
        if (e.target.closest('[data-sched-confirm-cancel]')) {
          finish(false);
        }
      };
      const onKey = (e) => {
        if (e.key === 'Escape') finish(false);
      };
      modal.addEventListener('click', onClick);
      document.addEventListener('keydown', onKey);
      modal.querySelector('[data-sched-confirm-yes]')?.focus();
    });
  }

  function notify(message, isError) {
    if (window.mcToast) {
      window.mcToast(message);
      return;
    }
    if (isError) {
      window.alert(message);
    }
  }

  function bindToggle(dayBlock) {
    const toggleBtn = dayBlock.querySelector('[data-toggle-day]');
    const body = dayBlock.querySelector('[data-day-body]');
    const isToday = dayBlock.dataset.isToday === '1';
    if (!toggleBtn || !body) return;

    function setOpen(open) {
      body.hidden = !open;
      toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      const label = toggleBtn.querySelector('[data-toggle-label]');
      if (label) {
        if (isToday) label.textContent = open ? 'Collapse' : 'Expand';
        else label.textContent = open ? 'Hide' : 'View';
      }
      dayBlock.classList.toggle('is-collapsed', !open);
    }

    if (!isToday) setOpen(false);
    else {
      dayBlock.classList.remove('is-collapsed');
      setOpen(true);
    }

    toggleBtn.addEventListener('click', (e) => {
      e.preventDefault();
      setOpen(!!body.hidden);
    });

    const head = dayBlock.querySelector('.sched-day__head');
    if (head) {
      head.addEventListener('click', (e) => {
        if (e.target.closest('[data-toggle-day], a, input, button, label')) return;
        setOpen(!!body.hidden);
      });
    }
  }

  function bindEditableDay(dayBlock) {
    const sessionsList = dayBlock.querySelector('[data-sessions-list]');
    const validationBox = dayBlock.querySelector('[data-sched-validation]');
    const addBtn = dayBlock.querySelector('[data-add-session]');
    const saveBtn = dayBlock.querySelector('.schedule-save-btn');

    if (addBtn && sessionsList) {
      addBtn.addEventListener('click', () => {
        const card = buildSessionCard();
        sessionsList.appendChild(card);
        renumberSessions(sessionsList);
        showValidation(validationBox, []);
        card.querySelector('.schedule-start')?.focus();
      });

      sessionsList.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove-session]');
        if (!btn) return;
        const card = btn.closest('[data-session-card]');
        const cards = sessionsList.querySelectorAll('[data-session-card]');
        if (cards.length <= 1) {
          showValidation(validationBox, ['Keep at least one session, or turn off bookings for today.']);
          return;
        }
        card?.remove();
        renumberSessions(sessionsList);
        showValidation(validationBox, validateSessions(sessionsList.querySelectorAll('[data-session-card]'), true));
      });

      sessionsList.addEventListener('change', () => {
        showValidation(validationBox, validateSessions(sessionsList.querySelectorAll('[data-session-card]'), true));
      });
    }

    if (!saveBtn) return;

    saveBtn.addEventListener('click', async () => {
      const day = dayBlock.dataset.day || '';
      if (dayBlock.dataset.editable !== '1' || day !== SCHEDULE_TODAY) {
        notify('Schedules lock at midnight. You can only edit today\'s availability (' + SCHEDULE_TODAY + ').', true);
        return;
      }

      const cards = sessionsList?.querySelectorAll('[data-session-card]') || [];
      const dayActive = dayBlock.querySelector('.schedule-day-active')?.checked ?? false;
      const errors = validateSessions(cards, dayActive);

      if (errors.length) {
        showValidation(validationBox, errors);
        return;
      }

      showValidation(validationBox, []);

      const ok = await confirmSave(day);
      if (!ok) return;

      const originalText = saveBtn.textContent;
      saveBtn.disabled = true;
      saveBtn.classList.add('is-saving');
      saveBtn.textContent = 'Saving…';

      const persistActive = !!(dayBlock.querySelector('.schedule-day-active')?.checked);
      const sessions = collectSessions(dayBlock).map((s) => ({
        ...s,
        is_active: persistActive ? 1 : 0,
        accept_bookings: persistActive ? 1 : 0,
        booking_enabled: persistActive ? 1 : 0,
      }));

      const fd = new FormData();
      fd.append('day', day);
      fd.append('accept_bookings', persistActive ? '1' : '0');
      fd.append('booking_enabled', persistActive ? '1' : '0');
      fd.append('is_active', persistActive ? '1' : '0');
      fd.append('sessions', JSON.stringify(sessions));
      const csrf = (document.body && document.body.dataset.csrf) || '';
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch(SCHEDULE_API, {
          method: 'POST',
          body: fd,
          credentials: 'include',
          cache: 'no-store',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-MC-No-Loader': '1',
          },
        });

        const raw = await res.text();
        let data;
        try {
          data = JSON.parse(raw);
        } catch {
          throw new Error(raw || 'Invalid server response.');
        }

        if (data.success) {
          saveBtn.classList.remove('is-saving');
          saveBtn.classList.add('is-success');
          saveBtn.textContent = 'Saved';
          notify(data.message || 'Schedule saved.');
          // Quiet refresh — no login/logout loader splash.
          setTimeout(() => {
            window.location.replace(window.location.pathname + window.location.search);
          }, 450);
          return;
        }

        if (data.errors?.length) {
          showValidation(validationBox, data.errors);
        } else if (data.message === 'Unauthorized.') {
          notify('Your session expired. Please log in again.', true);
          window.location.href = LOGIN_URL;
          return;
        } else {
          notify(data.message || 'Could not save schedule.', true);
        }
      } catch (err) {
        notify(err.message || 'Error saving schedule.', true);
      }

      saveBtn.disabled = false;
      saveBtn.classList.remove('is-saving');
      saveBtn.textContent = originalText;
    });
  }

  document.querySelectorAll('.sched-day').forEach((dayBlock) => {
    bindToggle(dayBlock);
    if (dayBlock.dataset.editable === '1') {
      bindEditableDay(dayBlock);
    }
  });

  function getCsrfToken() {
    return (document.body && document.body.dataset.csrf) || '';
  }

  async function removeSlot(slotId) {
    if (!REMOVE_SLOT_API || !slotId) return;
    if (!window.confirm('Remove this available time slot?')) return;

    const fd = new FormData();
    fd.append('slot_id', String(slotId));
    const csrf = getCsrfToken();
    if (csrf) fd.append('csrf_token', csrf);

    try {
      const res = await fetch(REMOVE_SLOT_API, {
        method: 'POST',
        body: fd,
        credentials: 'include',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-MC-No-Loader': '1' },
      });
      const data = await res.json();
      if (data.success) {
        notify(data.message || 'Slot removed.');
        refreshLive(true);
        return;
      }
      notify(data.message || 'Could not remove slot.', true);
    } catch (err) {
      notify(err.message || 'Error removing slot.', true);
    }
  }

  const rescheduleModal = document.getElementById('schedRescheduleModal');
  const rescheduleForm = document.getElementById('schedRescheduleForm');
  const reschedulePatient = document.getElementById('schedReschedulePatient');
  const rescheduleConsultId = document.getElementById('schedRescheduleConsultId');
  const rescheduleOldSlotId = document.getElementById('schedRescheduleOldSlotId');
  const rescheduleNewSlot = document.getElementById('schedRescheduleNewSlot');
  const rescheduleReason = document.getElementById('schedRescheduleReason');

  function closeRescheduleModal() {
    if (!rescheduleModal) return;
    rescheduleModal.hidden = true;
    rescheduleModal.setAttribute('aria-hidden', 'true');
  }

  async function loadRescheduleSlots(excludeSlotId) {
    if (!rescheduleNewSlot || !RESCHEDULE_SLOTS_API) return;
    rescheduleNewSlot.innerHTML = '<option value="">Loading…</option>';
    const url = RESCHEDULE_SLOTS_API
      + (excludeSlotId ? ('?exclude_slot_id=' + encodeURIComponent(excludeSlotId)) : '');
    const res = await fetch(url, { credentials: 'include', cache: 'no-store' });
    const data = await res.json();
    if (!data.success || !Array.isArray(data.slots) || !data.slots.length) {
      rescheduleNewSlot.innerHTML = '<option value="">No available slots today</option>';
      return;
    }
    rescheduleNewSlot.innerHTML = '<option value="">Select a new time</option>'
      + data.slots.map((s) => '<option value="' + s.id + '">' + escapeHtml(s.label) + '</option>').join('');
  }

  function openRescheduleModal(btn) {
    if (!rescheduleModal || !btn) return;
    const consultationId = btn.getAttribute('data-consultation-id') || '';
    const slotId = btn.getAttribute('data-reschedule-slot') || '';
    const patientName = btn.getAttribute('data-patient-name') || 'Patient';
    const slotTime = btn.getAttribute('data-slot-time') || '';

    if (rescheduleConsultId) rescheduleConsultId.value = consultationId;
    if (rescheduleOldSlotId) rescheduleOldSlotId.value = slotId;
    if (reschedulePatient) {
      reschedulePatient.textContent = patientName + ' — current time: ' + slotTime;
    }
    if (rescheduleReason) rescheduleReason.value = '';

    rescheduleModal.hidden = false;
    rescheduleModal.setAttribute('aria-hidden', 'false');
    loadRescheduleSlots(slotId);
  }

  const previewCard = document.querySelector('.sched-preview-card');
  if (previewCard) {
    previewCard.addEventListener('click', (e) => {
      const removeBtn = e.target.closest('[data-remove-slot]');
      if (removeBtn) {
        e.preventDefault();
        removeSlot(removeBtn.getAttribute('data-remove-slot'));
        return;
      }
      const rescheduleBtn = e.target.closest('[data-reschedule-slot]');
      if (rescheduleBtn) {
        e.preventDefault();
        openRescheduleModal(rescheduleBtn);
      }
    });
  }

  rescheduleModal?.querySelectorAll('[data-sched-reschedule-cancel]').forEach((el) => {
    el.addEventListener('click', closeRescheduleModal);
  });

  rescheduleForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!RESCHEDULE_API) return;

    const consultationId = rescheduleConsultId?.value || '';
    const newSlotId = rescheduleNewSlot?.value || '';
    const reason = (rescheduleReason?.value || '').trim();
    if (!consultationId || !newSlotId || !reason) {
      notify('Please select a new time and provide a reason.', true);
      return;
    }

    const fd = new FormData();
    fd.append('consultation_id', consultationId);
    fd.append('new_slot_id', newSlotId);
    fd.append('reason', reason);
    const csrf = getCsrfToken();
    if (csrf) fd.append('csrf_token', csrf);

    const submitBtn = rescheduleForm.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const res = await fetch(RESCHEDULE_API, {
        method: 'POST',
        body: fd,
        credentials: 'include',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-MC-No-Loader': '1' },
      });
      const data = await res.json();
      if (data.success) {
        notify(data.message || 'Reschedule request sent.');
        closeRescheduleModal();
        refreshLive(true);
        return;
      }
      notify(data.message || 'Could not send reschedule request.', true);
    } catch (err) {
      notify(err.message || 'Error sending reschedule request.', true);
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  let liveFingerprint = window.SCHEDULE_CONFIG?.liveFingerprint || '';
  let liveSlots = [];
  let liveInFlight = false;
  let liveTimer = null;

  function plural(n, one, many) {
    return n + ' ' + (n === 1 ? one : many);
  }

  function slotCardHtml(slot) {
    let html = '<div class="sched-slot-card ' + escapeHtml(slot.card_class || 'is-available')
      + '" data-slot-id="' + String(slot.id || 0) + '">';
    html += '<div class="sched-slot-time">' + escapeHtml(slot.label || '') + '</div>';
    html += '<div class="sched-slot-status" title="' + escapeHtml(slot.display_status || '') + '">'
      + escapeHtml(slot.display_status || '') + '</div>';

    if (slot.is_booked && slot.patient_name) {
      html += '<div class="sched-slot-patient" title="' + escapeHtml(slot.patient_name) + '">'
        + escapeHtml(slot.patient_name) + '</div>';
    }

    if (slot.is_booked && slot.pending_reschedule) {
      html += '<div class="sched-slot-pending"><p class="sched-slot-note sched-slot-note--pending">'
        + 'Reschedule pending — patient must confirm</p>';
      if (slot.reschedule_reason) {
        html += '<p class="sched-slot-reason"><strong>Reason:</strong> '
          + escapeHtml(slot.reschedule_reason) + '</p>';
      }
      if (slot.reschedule_new_label) {
        html += '<p class="sched-slot-proposed"><strong>Proposed:</strong> '
          + escapeHtml(slot.reschedule_new_label);
        if (slot.reschedule_old_label && slot.status === 'booked') {
          html += ' <span class="sched-slot-proposed-was">(was '
            + escapeHtml(slot.reschedule_old_label) + ')</span>';
        }
        html += '</p>';
      }
      html += '</div>';
    } else if (slot.is_booked) {
      html += '<p class="sched-slot-note sched-slot-note--locked">'
        + 'BOOKED — This time slot cannot be changed because a patient has an appointment.</p>';
    }

    html += '<div class="sched-slot-actions">';
    if (slot.can_remove) {
      html += '<button type="button" class="sched-slot-btn sched-slot-btn--danger" data-remove-slot="'
        + String(slot.id) + '">Remove</button>';
    } else if (slot.can_reschedule) {
      html += '<button type="button" class="sched-slot-btn sched-slot-btn--primary"'
        + ' data-reschedule-slot="' + String(slot.id) + '"'
        + ' data-consultation-id="' + String(slot.consultation_id || 0) + '"'
        + ' data-patient-name="' + escapeHtml(slot.patient_name || 'Patient') + '"'
        + ' data-slot-time="' + escapeHtml(slot.label || '') + '">Reschedule</button>';
    } else if (slot.is_past || ['completed', 'cancelled', 'expired'].indexOf(slot.status) !== -1) {
      html += '<span class="sched-slot-view-only">View only</span>';
    }
    html += '</div></div>';
    return html;
  }

  function renderLivePanel(data) {
    const panel = document.querySelector('[data-sched-live-panel]');
    if (!panel) return;

    const dayName = data.today || SCHEDULE_TODAY || 'Today';
    const counts = data.counts || {};
    const slots = Array.isArray(data.slots) ? data.slots : [];
    const isActive = !!data.is_active;

    let html = isActive
      ? '<div class="sched-status-banner sched-status-banner--ok"><strong>'
        + escapeHtml(dayName) + ' is active.</strong> Slots from all sessions appear below in chronological order.</div>'
      : '<div class="sched-status-banner sched-status-banner--warn"><strong>'
        + escapeHtml(dayName) + ' is inactive.</strong> Add sessions, enable bookings, and click <strong>Save</strong>.</div>';

    if (slots.length) {
      html += '<div class="sched-slot-stats">'
        + '<div class="sched-slot-stat sched-slot-stat--open"><strong data-sched-count="available">'
        + String(counts.available || 0) + '</strong><span>Available</span></div>'
        + '<div class="sched-slot-stat sched-slot-stat--booked"><strong data-sched-count="booked">'
        + String(counts.booked || 0) + '</strong><span>Booked</span></div>'
        + '<div class="sched-slot-stat sched-slot-stat--past"><strong data-sched-count="passed">'
        + String(counts.passed || 0) + '</strong><span>Expired</span></div>'
        + '</div>'
        + '<p class="sched-slot-legend">Only <strong>AVAILABLE</strong> slots can be removed. '
        + '<strong>BOOKED</strong> appointments must use Reschedule — never edit the time directly.</p>'
        + '<h4 class="sched-preview-title">' + escapeHtml(dayName) + ' timeline</h4>'
        + '<div class="sched-slot-grid-wrap"><div class="sched-slot-grid">'
        + slots.map(slotCardHtml).join('')
        + '</div></div>';
    } else if (isActive) {
      html += '<p class="sched-preview-empty">Sessions are active but no slots were generated yet.<br>'
        + 'Configure your sessions and click <strong>Save ' + escapeHtml(dayName) + ' Schedule</strong>.</p>';
    } else {
      html += '<p class="sched-preview-empty">No slots for today.<br>Add sessions, enable bookings, and save.</p>';
    }

    panel.innerHTML = html;

    const activeChip = document.querySelector('[data-sched-live-active]');
    if (activeChip) {
      activeChip.textContent = 'Today: ' + (isActive ? 'Accepting bookings' : 'Not active');
    }
    const sessionChip = document.querySelector('[data-sched-live-sessions]');
    if (sessionChip && typeof data.session_count === 'number') {
      sessionChip.textContent = plural(data.session_count, 'session', 'sessions') + ' today';
    }
    const slotChip = document.querySelector('[data-sched-live-slot-count]');
    if (slotChip) {
      slotChip.textContent = plural(slots.length, 'slot', 'slots') + ' generated';
    }
  }

  function announceLiveChanges(prevSlots, nextSlots) {
    if (!prevSlots.length) return;
    const prevMap = new Map(prevSlots.map((s) => [String(s.id), s]));
    const booked = [];
    const cancelled = [];
    nextSlots.forEach((slot) => {
      const prev = prevMap.get(String(slot.id));
      if (!prev) return;
      const wasBooked = !!prev.is_booked;
      const nowBooked = !!slot.is_booked;
      if (!wasBooked && nowBooked) booked.push(slot.label || 'a time');
      if (wasBooked && !nowBooked) cancelled.push(slot.label || 'a time');
    });
    if (booked.length === 1) {
      notify('Patient booked ' + booked[0] + '.');
    } else if (booked.length > 1) {
      notify(booked.length + ' slots were just booked.');
    }
    if (cancelled.length === 1) {
      notify('Appointment cancelled — ' + cancelled[0] + ' is available again.');
    } else if (cancelled.length > 1) {
      notify(cancelled.length + ' appointments were cancelled. Those times are open again.');
    }
  }

  async function refreshLive(force) {
    if (!LIVE_API || liveInFlight) return;
    if (document.hidden && !force) return;
    liveInFlight = true;
    try {
      const res = await fetch(LIVE_API, {
        method: 'GET',
        credentials: 'include',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-MC-No-Loader': '1' },
      });
      const data = await res.json();
      if (!data || !data.success) return;
      const nextFp = data.fingerprint || '';
      const nextSlots = Array.isArray(data.slots) ? data.slots : [];
      if (!force && nextFp && nextFp === liveFingerprint) {
        liveSlots = nextSlots;
        return;
      }
      if (liveSlots.length) {
        announceLiveChanges(liveSlots, nextSlots);
      }
      liveFingerprint = nextFp;
      liveSlots = nextSlots;
      renderLivePanel(data);
    } catch (err) {
      /* next poll retries */
    } finally {
      liveInFlight = false;
    }
  }

  function startLivePolling() {
    if (!LIVE_API || !document.querySelector('[data-sched-live-panel]')) return;
    refreshLive(false);
    liveTimer = window.setInterval(() => {
      if (document.hidden) return;
      if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
      refreshLive(false);
    }, LIVE_POLL_MS);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) refreshLive(false);
    });
    document.addEventListener('medconnect:live-sync', (ev) => {
      const changed = (ev.detail && ev.detail.changed) || [];
      if (changed.indexOf('slots') !== -1 || changed.indexOf('schedule') !== -1 || changed.indexOf('appointments') !== -1) {
        refreshLive(false);
      }
    });
  }

  startLivePolling();

  window.MedConnectProviderScheduleLive = {
    refresh: refreshLive,
  };
})();
