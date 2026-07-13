/**
 * Patient portal — navigation, mobile sidebar, triage submit
 */
(function () {
  'use strict';

  const APP_BASE = window.APP_BASE || '';

  function closeMobileNav() {
    const sidebar = document.querySelector('.sidebar');
    const backdrop = document.querySelector('.mc-nav-backdrop');
    if (sidebar) sidebar.classList.remove('is-open');
    if (backdrop) backdrop.classList.remove('is-visible');
    document.body.classList.remove('mc-nav-open');
    document.querySelectorAll('#mcNavToggle, #pdHamburger').forEach((btn) => {
      btn.setAttribute('aria-expanded', 'false');
    });
  }

  window.switchView = function switchView(viewId) {
    document.querySelectorAll('.view-container').forEach((v) => v.classList.remove('active'));
    const activeView = document.getElementById('view-' + viewId);
    if (activeView) {
      activeView.classList.add('active');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    if (viewId) {
      const nextHash = '#view-' + viewId;
      if (window.location.hash !== nextHash) {
        window.history.replaceState(null, '', nextHash);
      }
    }

    document.querySelectorAll('.sb-item').forEach((item) => {
      item.classList.toggle('active', item.dataset.view === viewId);
    });

    if (viewId === 'consultations' && typeof window.filterSessions === 'function') {
      window.filterSessions('upcoming');
    }

    if (viewId === 'triage' && typeof window.refreshBookingPicker === 'function') {
      window.refreshBookingPicker();
    }

    closeMobileNav();
  };

  function parseConsultDate(dateStr) {
    if (!dateStr) return 0;
    const d = new Date(String(dateStr).split(' ')[0] + 'T00:00:00');
    return d.getTime();
  }

  function consultDateYmd(dateStr) {
    if (!dateStr) return '';
    return String(dateStr).split(' ')[0];
  }

  function localTodayYmd() {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const d = String(now.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function scheduledStartMs(c) {
    const date = consultDateYmd(c.slot_date || c.consult_date);
    if (!date) return 0;
    const time = String(c.slot_start || c.consult_time || '00:00:00');
    const parts = date.split('-').map(Number);
    const timeParts = time.split(':').map(Number);
    if (parts.length !== 3) return 0;
    return new Date(
      parts[0],
      parts[1] - 1,
      parts[2],
      timeParts[0] || 0,
      timeParts[1] || 0,
      timeParts[2] || 0
    ).getTime();
  }

  function formatOpensAt(ms) {
    if (!ms) return 'scheduled time';
    return new Date(ms).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }

  function isBeforeScheduledStart(c) {
    const start = scheduledStartMs(c);
    return start > 0 && Date.now() < start;
  }

  function consultationJoinAccess(c) {
    const status = String(c.status || '')
      .toLowerCase()
      .trim()
      .replace(/\s+/g, '_');

    // Best practice: join only after provider started the live room.
    if (status === 'in_consultation' && c.room_token) {
      return { allowed: true, mode: 'join' };
    }

    if (isBeforeScheduledStart(c)) {
      return {
        allowed: false,
        mode: 'scheduled_wait',
        opensAt: formatOpensAt(scheduledStartMs(c)),
      };
    }

    const isToday = consultDateYmd(c.consult_date) === localTodayYmd();

    if (status === 'in_consultation') {
      return { allowed: false, mode: 'waiting' };
    }

    if (isToday && (status === 'scheduled' || status === 'pending' || status === 'waiting')) {
      return { allowed: false, mode: 'waiting' };
    }

    return { allowed: false, mode: 'unavailable' };
  }

  function providerInitials(name) {
    const n = String(name || '').trim();
    if (!n) return 'DR';
    const parts = n.replace(/^dr\.?\s*/i, '').split(/\s+/).filter(Boolean);
    const first = (parts[0] || '').charAt(0);
    const last = (parts[parts.length - 1] || '').charAt(0);
    return (first + last).toUpperCase() || 'DR';
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function statusBadgeClass(mode, status) {
    if (mode === 'join') return 'psess-status--ready';
    if (mode === 'waiting') return 'psess-status--waiting';
    if (mode === 'scheduled_wait') return 'psess-status--scheduled';
    if (String(status).toLowerCase() === 'cancelled') return 'psess-status--cancelled';
    return 'psess-status--past';
  }

  function updateSessionMetrics(list) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const todayMs = today.getTime();
    let upcoming = 0;
    let ready = 0;
    let past = 0;
    (list || []).forEach((c) => {
      const isUpcoming = parseConsultDate(c.consult_date) >= todayMs && c.status !== 'cancelled';
      if (isUpcoming) {
        upcoming++;
        if (consultationJoinAccess(c).mode === 'join') ready++;
      } else {
        past++;
      }
    });
    const elUp = document.getElementById('psess-metric-upcoming');
    const elReady = document.getElementById('psess-metric-ready');
    const elPast = document.getElementById('psess-metric-past');
    if (elUp) elUp.textContent = String(upcoming);
    if (elReady) elReady.textContent = String(ready);
    if (elPast) elPast.textContent = String(past);
  }

  window.filterSessions = function filterSessions(type) {
    const container = document.getElementById('sessions-list');
    const list = window.consultations || [];
    if (!container) return;

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const todayMs = today.getTime();

    updateSessionMetrics(list);

    const filtered = list.filter((c) => {
      const isUpcoming =
        parseConsultDate(c.consult_date) >= todayMs && c.status !== 'cancelled';
      return type === 'upcoming' ? isUpcoming : !isUpcoming;
    });

    container.innerHTML = '';
    const pastCount = (list || []).filter((c) => {
      const isUpcoming = parseConsultDate(c.consult_date) >= todayMs && c.status !== 'cancelled';
      return !isUpcoming;
    }).length;

    if (filtered.length === 0) {
      if (type === 'upcoming') {
        container.innerHTML =
          '<div class="psess-empty">' +
          '<div class="psess-empty__icon" aria-hidden="true">' +
          '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 10l4.553-2.276A1 1 0 0 1 21 8.618v6.764a1 1 0 0 1-1.447.894L15 14M5 18h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z"/></svg>' +
          '</div>' +
          '<p>No upcoming sessions</p>' +
          '<p class="psess-empty__sub">Schedule a video visit with an available provider.</p>' +
          '<div class="psess-empty__actions">' +
          '<a href="' + APP_BASE + '/views/patient/triage.php" class="psess-btn psess-btn--primary">Book Consultation</a>' +
          (pastCount > 0
            ? '<a href="#" class="psess-empty__link" data-psess-switch-tab="past">View ' + pastCount + ' past visit' + (pastCount !== 1 ? 's' : '') + ' →</a>'
            : '<a href="' + APP_BASE + '/views/patient/my_health.php" class="psess-empty__link">Browse My Health records →</a>') +
          '</div></div>';
      } else {
        container.innerHTML =
          '<div class="psess-empty">' +
          '<div class="psess-empty__icon" aria-hidden="true">' +
          '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
          '</div>' +
          '<p>No past sessions yet</p>' +
          '<p class="psess-empty__sub">After your first visit, notes and prescriptions appear in My Health.</p>' +
          '<div class="psess-empty__actions">' +
          '<a href="' + APP_BASE + '/views/patient/triage.php" class="psess-btn psess-btn--primary">Book Consultation</a>' +
          '<a href="' + APP_BASE + '/views/patient/my_health.php" class="psess-empty__link">Go to My Health →</a>' +
          '</div></div>';
      }
      container.querySelectorAll('[data-psess-switch-tab]').forEach((link) => {
        link.addEventListener('click', (e) => {
          e.preventDefault();
          window.filterSessions(link.getAttribute('data-psess-switch-tab') || 'past');
        });
      });
      updateJoinHint(type === 'upcoming' ? filtered : []);
      return;
    }

    filtered.forEach((c) => {
      const joinAccess =
        type === 'upcoming'
          ? consultationJoinAccess(c)
          : { allowed: false, mode: 'past' };

      let actionBtn = '';
      if (type === 'upcoming') {
        if (joinAccess.allowed) {
          actionBtn =
            '<a href="' + APP_BASE + '/views/consultation/video_room.php?token=' +
            encodeURIComponent(c.room_token) +
            '" class="psess-btn psess-btn--primary">Join Video Call</a>';
        } else if (joinAccess.mode === 'scheduled_wait') {
          actionBtn =
            '<button type="button" class="psess-btn psess-btn--outline" disabled>Opens ' +
            (joinAccess.opensAt || 'at scheduled time') + '</button>';
        } else if (joinAccess.mode === 'waiting') {
          actionBtn =
            '<button type="button" class="psess-btn psess-btn--outline psess-waiting-pulse" disabled>Waiting for Provider</button>';
        } else {
          actionBtn =
            '<button type="button" class="psess-btn psess-btn--outline" disabled>Not Available Yet</button>';
        }
      } else {
        actionBtn =
          '<div class="psess-card__actions">' +
          '<a href="' + APP_BASE + '/views/patient/my_health.php?tab=timeline" class="psess-btn psess-btn--outline">Care Timeline</a>' +
          '<a href="' + APP_BASE + '/views/patient/my_health.php?tab=files" class="psess-btn psess-btn--outline">Health Files</a>' +
          '</div>';
      }

      const dateLabel = c.consult_date
        ? new Date(c.consult_date + 'T00:00:00').toLocaleDateString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric',
          })
        : '—';

      let timeLabel = '';
      if (c.consult_time) {
        const tp = String(c.consult_time).split(':');
        const th = parseInt(tp[0] || '0', 10);
        const tm = String(tp[1] || '00').padStart(2, '0');
        const ampm = th >= 12 ? 'PM' : 'AM';
        const h12 = ((th + 11) % 12) + 1;
        timeLabel = h12 + ':' + tm + ' ' + ampm;
      }

      const statusLabel =
        joinAccess.mode === 'waiting' ? 'Waiting for provider'
          : joinAccess.mode === 'join' ? 'Ready to join'
          : joinAccess.mode === 'scheduled_wait' ? 'Opens at scheduled time'
          : (c.status || '—');

      const cardMod =
        joinAccess.mode === 'join' ? ' psess-card--ready'
          : joinAccess.mode === 'waiting' ? ' psess-card--waiting' : '';

      container.innerHTML +=
        '<article class="psess-card' + cardMod + '" data-consult-id="' + (c.id || '') + '">' +
        '<div class="psess-card__main">' +
        '<div class="psess-card__avatar">' + providerInitials(c.provider_name) + '</div>' +
        '<div class="psess-card__info">' +
        '<h3>' + escapeHtml(c.provider_name || 'Healthcare Provider') + '</h3>' +
        '<p class="psess-card__type">' + escapeHtml(c.consult_type || 'General Consultation') + '</p>' +
        '<p class="psess-card__datetime">' +
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>' +
        dateLabel + (timeLabel ? ' · ' + timeLabel : '') +
        '</p></div></div>' +
        '<div class="psess-card__aside">' +
        '<span class="psess-status ' + statusBadgeClass(joinAccess.mode, c.status) + '">' + escapeHtml(statusLabel) + '</span>' +
        actionBtn +
        '</div></article>';
    });

    updateJoinHint(type === 'upcoming' ? filtered : []);
  };

  function updateJoinHint(list) {
    const hint = document.getElementById('consult-join-hint');
    const banner = document.getElementById('psess-live-banner');
    const bannerTitle = document.getElementById('psess-live-banner-title');
    const bannerSub = document.getElementById('psess-live-banner-sub');
    const waiting = (list || []).some((c) => consultationJoinAccess(c).mode === 'waiting');
    const ready = (list || []).some((c) => consultationJoinAccess(c).allowed);

    if (banner && bannerTitle) {
      if (ready) {
        banner.hidden = false;
        banner.className = 'psess-live-banner psess-live-banner--ready';
        bannerTitle.textContent = 'Your provider started the room';
        if (bannerSub) bannerSub.textContent = 'Click Join Video Call on your session card when you are ready.';
      } else if (waiting) {
        banner.hidden = false;
        banner.className = 'psess-live-banner';
        bannerTitle.textContent = 'Waiting for your provider';
        if (bannerSub) bannerSub.textContent = 'Join will unlock automatically when they start the video room.';
      } else {
        banner.hidden = true;
      }
    }

    if (!hint) return;
    if (ready) {
      hint.hidden = false;
      hint.textContent = 'Your provider started the room — click Join Video Call when you are ready.';
    } else if (waiting) {
      hint.hidden = false;
      hint.textContent = 'Checking for your provider… Join will appear automatically when they start.';
    } else {
      hint.hidden = true;
      hint.textContent = '';
    }
  }

  async function refreshConsultationStatus() {
    if (!document.getElementById('sessions-list')) return;
    try {
      const res = await fetch(APP_BASE + '/app/api/consultations/consultation_status.php', {
        credentials: 'same-origin',
        cache: 'no-store',
      });
      const json = await res.json();
      if (!json || !json.success || !Array.isArray(json.items)) return;

      const byId = {};
      (window.consultations || []).forEach((c) => {
        byId[String(c.id)] = c;
      });
      json.items.forEach((item) => {
        const id = String(item.id);
        byId[id] = Object.assign({}, byId[id] || {}, item);
      });
      window.consultations = Object.keys(byId).map((k) => byId[k]);

      const tab = document.querySelector('.psess-tab.is-active') || document.querySelector('.tab-btn.active');
      const type = tab?.dataset?.sessTab || (tab && /past/i.test(tab.textContent || '') ? 'past' : 'upcoming');
      if (typeof window.filterSessions === 'function') {
        window.filterSessions(type);
      }
    } catch (_) {
      /* non-fatal */
    }
  }

  // Live poll while waiting for provider to start the room.
  if (document.getElementById('sessions-list')) {
    refreshConsultationStatus();
    setInterval(refreshConsultationStatus, 5000);
  }

  // Keep tab highlight in sync when filterSessions is called from elsewhere.
  const _origFilterSessions = window.filterSessions;
  window.filterSessions = function filterSessionsWrapped(type) {
    _origFilterSessions(type);
    document.querySelectorAll('.psess-tab').forEach((btn) => {
      const match = (btn.dataset.sessTab || '') === type;
      btn.classList.toggle('is-active', match);
      btn.setAttribute('aria-selected', match ? 'true' : 'false');
    });
    document.querySelectorAll('.tab-btn').forEach((btn) => {
      btn.classList.toggle('active', btn.innerText.toLowerCase().includes(type));
    });
  };

  window.filterRecords = function filterRecords(type) {
    document.querySelectorAll('#records-tbody tr[data-type]').forEach((row) => {
      row.style.display = type === 'all' || row.dataset.type === type ? '' : 'none';
    });
  };

  let bookingPickerReady = false;

  function initBookingPicker() {
    const providerSelect = document.getElementById('booking_provider');
    const dateInput = document.getElementById('booking_date');
    const dateDisplay = document.getElementById('booking_date_display');
    const slotsWrap = document.getElementById('bookingSlotsWrap');
    const slotInput = document.getElementById('booking_slot_id');
    if (!providerSelect || !dateInput || !slotsWrap || !slotInput) return;
    if (bookingPickerReady) return;
    bookingPickerReady = true;

    const clearSlots = (message) => {
      slotInput.value = '';
      slotsWrap.innerHTML = '<p class="text-xs text-muted">' + message + '</p>';
    };

    const bookingBlocked = window.BOOKING_BLOCKED_IN_CONSULTATION === true;
    if (bookingBlocked) {
      providerSelect.disabled = true;
      clearSlots('Booking is unavailable while your consultation is in progress. Finish the visit first.');
      return;
    }

    const renderSlots = (slots) => {
      slotInput.value = '';
      const bookableSlots = slots.filter((slot) => slot.bookable !== false && isSlotStartInFuture(slot));

      if (!slots.length) {
        clearSlots('No appointment slots were generated for today. Ask the provider to enable today in their schedule.');
        return;
      }

      slotsWrap.innerHTML = '';

      if (!bookableSlots.length) {
        const note = document.createElement('p');
        note.className = 'text-xs text-muted';
        note.textContent =
          'All of today\'s slots have passed. Past times are shown below but cannot be selected.';
        slotsWrap.appendChild(note);
      }

      const grid = document.createElement('div');
      grid.className = 'booking-slots-grid';

      slots.forEach((slot) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        const isBookable = slot.bookable !== false && isSlotStartInFuture(slot);
        btn.className = 'booking-slot-btn' + (isBookable ? '' : ' is-past');
        const baseLabel = String(slot.label || '').replace(/\s*\(passed\)\s*$/i, '');
        btn.textContent = isBookable ? baseLabel : baseLabel + ' (passed)';
        btn.dataset.slotId = String(slot.id);
        btn.disabled = !isBookable;
        btn.setAttribute('aria-disabled', isBookable ? 'false' : 'true');

        if (isBookable) {
          btn.addEventListener('click', () => {
            slotsWrap.querySelectorAll('.booking-slot-btn').forEach((el) => el.classList.remove('is-selected'));
            btn.classList.add('is-selected');
            slotInput.value = String(slot.id);
          });
        }

        grid.appendChild(btn);
      });

      slotsWrap.appendChild(grid);

      // Urgent post-registration: auto-select earliest bookable slot
      try {
        const preferEarliest = sessionStorage.getItem('medconnect_prefer_earliest_slot') === '1';
        if (preferEarliest) {
          const firstBookable = slotsWrap.querySelector('.booking-slot-btn:not(.is-past):not([disabled])');
          if (firstBookable) {
            firstBookable.click();
            firstBookable.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        }
      } catch (_) { /* ignore */ }
    };

    const localTodayYmd = () => {
      const now = new Date();
      const y = now.getFullYear();
      const m = String(now.getMonth() + 1).padStart(2, '0');
      const d = String(now.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    };

    const isSlotStartInFuture = (slot) => {
      const parts = String(slot.slot_date || '').split('-').map(Number);
      const timeParts = String(slot.start_time || '00:00:00').split(':').map(Number);
      if (parts.length !== 3 || timeParts.length < 2) {
        return false;
      }
      const slotStart = new Date(parts[0], parts[1] - 1, parts[2], timeParts[0], timeParts[1], timeParts[2] || 0);
      return slotStart.getTime() > Date.now();
    };

    const formatSlotDateLabel = (slotDate) => {
      const parts = String(slotDate).split('-').map(Number);
      if (parts.length !== 3 || parts.some((n) => Number.isNaN(n))) {
        return slotDate;
      }
      const dateObj = new Date(parts[0], parts[1] - 1, parts[2]);
      const label = dateObj.toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
      });
      return slotDate === localTodayYmd() ? `Today — ${label}` : label;
    };

    const setTodayDisplay = (today) => {
      dateInput.value = today;
      if (dateDisplay) {
        dateDisplay.textContent = formatSlotDateLabel(today);
        dateDisplay.dataset.today = today;
      }
    };

    const loadTodayBooking = async (providerId) => {
      const today = dateInput.value || dateDisplay?.dataset.today || localTodayYmd();
      setTodayDisplay(today);
      clearSlots('Loading today\'s available slots…');

      try {
        await loadSlots(providerId, today);
      } catch {
        clearSlots('Could not load today\'s appointment slots.');
      }
    };

    const loadSlots = async (providerId, date) => {
      const today = dateInput.value || dateDisplay?.dataset.today || localTodayYmd();
      if (date !== today) {
        clearSlots('Appointments can only be booked for today.');
        return;
      }

      clearSlots('Loading available slots…');
      const url =
        APP_BASE +
        '/app/api/appointments/get_available_slots.php?provider_id=' +
        encodeURIComponent(providerId) +
        '&date=' +
        encodeURIComponent(today) +
        '&_=' +
        Date.now();

      const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      const data = await res.json();
      const slots = data.data?.slots || data.slots || [];

      if (!data.success) {
        clearSlots(data.message || 'Could not load today\'s slots.');
        return;
      }

      if (!slots.length) {
        clearSlots(
          'No slots for today. The doctor has not opened today\'s schedule yet or all clinic hours have passed.'
        );
        return;
      }

      renderSlots(slots);
    };

    providerSelect.addEventListener('change', () => {
      const providerId = providerSelect.value;
      if (!providerId) {
        clearSlots('Select a provider to load today\'s available slots.');
        return;
      }
      loadTodayBooking(providerId);
    });

    if (providerSelect.options.length === 2) {
      providerSelect.selectedIndex = 1;
      providerSelect.dispatchEvent(new Event('change'));
    }
  }

  window.refreshBookingPicker = function refreshBookingPicker() {
    const providerSelect = document.getElementById('booking_provider');
    if (!providerSelect || !providerSelect.value) {
      if (providerSelect && providerSelect.options.length === 2) {
        providerSelect.selectedIndex = 1;
        providerSelect.dispatchEvent(new Event('change'));
      }
      return;
    }
    providerSelect.dispatchEvent(new Event('change'));
  };

  function showTriageAlert(alertEl, type, message) {
    if (!alertEl) return;
    alertEl.className = 'patient-triage-alert patient-triage-alert--' + type + ' is-visible';
    alertEl.textContent = message;
    alertEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  const BOOKING_OVERLAY_STEPS = [
    'Reviewing your health concern…',
    'Confirming provider availability…',
    'Reserving your time slot…',
    'Finalizing your appointment…',
  ];

  let bookingOverlayTimer = null;

  function paintBookingOverlaySteps(active) {
    const steps = document.getElementById('patient-booking-overlay-steps');
    if (!steps) return;
    steps.innerHTML = BOOKING_OVERLAY_STEPS.map((label, idx) => {
      const done = idx < active;
      const current = idx === active;
      const icon = done ? '✓' : current ? '…' : '○';
      return (
        '<li class="patient-booking-overlay__step' +
        (done ? ' is-done' : '') +
        (current ? ' is-active' : '') +
        '"><span aria-hidden="true">' + icon + '</span> ' + label + '</li>'
      );
    }).join('');
  }

  function showBookingOverlay(visible, options) {
    const L = window.MedConnectGlobalLoader || window.MedConnectLoader;
    if (L && typeof L.showFormal === 'function') {
      if (visible) {
        L.showFormal(Object.assign({
          preset: 'booking',
          status: 'Booking your appointment…',
          substatus: 'Reserving your time slot…',
        }, options || {}));
      } else {
        L.hideFormal();
      }
      return;
    }

    if (L && typeof L.showPersistent === 'function') {
      if (visible) {
        L.showPersistent('patient-booking-overlay', { preset: 'booking' });
      } else {
        L.hidePersistent('patient-booking-overlay');
      }
      return;
    }

    const overlay = document.getElementById('patient-booking-overlay');
    if (!overlay) return;
    if (visible) {
      overlay.hidden = false;
      overlay.setAttribute('aria-hidden', 'false');
      document.body.classList.add('patient-booking-overlay-open');
      let active = 0;
      paintBookingOverlaySteps(active);
      if (bookingOverlayTimer) clearInterval(bookingOverlayTimer);
      bookingOverlayTimer = setInterval(() => {
        active = Math.min(active + 1, BOOKING_OVERLAY_STEPS.length - 1);
        paintBookingOverlaySteps(active);
      }, 850);
    } else {
      if (bookingOverlayTimer) {
        clearInterval(bookingOverlayTimer);
        bookingOverlayTimer = null;
      }
      overlay.hidden = true;
      overlay.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('patient-booking-overlay-open');
    }
  }

  function initTriageForm() {
    const form = document.getElementById('patientTriageForm');
    if (!form) return;

    initBookingPicker();

    const alertEl = document.getElementById('triageFormAlert');
    const submitBtn = form.querySelector('button[type="submit"]');
    const complaintEl = form.querySelector('#chief_complaint');

    // Prefill from registration if the patient just signed up
    try {
      const pending = sessionStorage.getItem('medconnect_pending_chief_complaint');
      if (pending && complaintEl && !complaintEl.value.trim()) {
        complaintEl.value = pending;
        complaintEl.removeAttribute('readonly');
        complaintEl.rows = 2;
        sessionStorage.removeItem('medconnect_pending_chief_complaint');
      }

      const blockTele = sessionStorage.getItem('medconnect_block_telemedicine') === '1';
      if (blockTele) {
        sessionStorage.removeItem('medconnect_block_telemedicine');
        showTriageAlert(
          alertEl,
          'error',
          'Emergency symptoms were flagged during registration. Please seek care at the nearest hospital or emergency department instead of booking an online consultation.'
        );
        if (submitBtn) submitBtn.disabled = true;
        const providerSelect = document.getElementById('booking_provider');
        if (providerSelect) providerSelect.disabled = true;
      }

      const preferEarliest = sessionStorage.getItem('medconnect_prefer_earliest_slot') === '1';
      if (preferEarliest) {
        showTriageAlert(
          alertEl,
          'success',
          'Based on your registration review, the earliest available slot will be selected. Confirm below or choose another time if needed.'
        );
        setTimeout(() => {
          if (typeof window.refreshBookingPicker === 'function') {
            window.refreshBookingPicker();
          }
        }, 400);
      }
    } catch (_) { /* ignore */ }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const complaint = (form.querySelector('#chief_complaint')?.value || '').trim();
      const slotId = document.getElementById('booking_slot_id')?.value || '';

      if (!complaint) {
        showTriageAlert(alertEl, 'error', 'Your health concern from registration is missing. Please contact support or update your profile.');
        return;
      }

      if (!slotId) {
        showTriageAlert(
          alertEl,
          'error',
          'Please choose an available time slot for today.'
        );
        return;
      }

      const fd = new FormData(form);
      fd.set('slot_id', slotId);
      const csrfToken = document.body?.dataset?.csrf || '';
      if (csrfToken) {
        fd.set('csrf_token', csrfToken);
      }

      try {
        const pendingNlp = sessionStorage.getItem('medconnect_pending_nlp_result');
        if (pendingNlp) {
          fd.set('registration_nlp_json', pendingNlp);
        }
      } catch (_) { /* ignore */ }

      showBookingOverlay(true);

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.dataset.originalText = submitBtn.textContent;
        submitBtn.textContent = 'Booking…';
      }

      try {
        const res = await fetch(APP_BASE + '/app/api/patient/submit_triage.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
        });

        let data;
        try {
          data = await res.json();
        } catch {
          showBookingOverlay(false);
          showTriageAlert(alertEl, 'error', 'Unexpected server response. Please try again.');
          return;
        }

        if (data.success) {
          const booked = data.booked !== false;
          try {
            sessionStorage.removeItem('medconnect_pending_nlp_result');
            sessionStorage.removeItem('medconnect_prefer_earliest_slot');
            sessionStorage.removeItem('medconnect_post_reg_urgency');
            sessionStorage.removeItem('medconnect_pending_chief_complaint');
          } catch (_) { /* ignore */ }
          showBookingOverlay(false);
          showTriageAlert(
            alertEl,
            booked ? 'success' : 'error',
            booked
              ? (data.message || 'Your appointment has been booked successfully.')
              : (data.message || 'Your visit was recorded, but the slot could not be booked.')
          );
          if (booked) {
            setTimeout(() => window.location.reload(), 1600);
          }
        } else {
          showBookingOverlay(false);
          showTriageAlert(alertEl, 'error', data.message || 'Could not book your appointment.');
        }
      } catch {
        showBookingOverlay(false);
        showTriageAlert(alertEl, 'error', 'Network error. Please try again.');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = submitBtn.dataset.originalText || 'Book Appointment';
        }
      }
    });
  }

  function syncSidebarFromRoute() {
    const pathMatch = window.location.pathname.match(/\/views\/patient\/([^/?#]+)/);
    if (!pathMatch) return false;

    const pageFile = pathMatch[1];
    const pageStem = pageFile.replace(/\.php$/i, '');
    document.querySelectorAll('.sb-item').forEach((item) => {
      item.classList.toggle('active', item.dataset.view === pageStem);
    });
    return true;
  }

  function scrollToDashboardAnchor() {
    const hash = (window.location.hash || '').replace('#', '');
    if (hash === 'action-items') {
      const el = document.getElementById('dashboardActionItems');
      if (el) {
        setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
      }
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    syncSidebarFromRoute();
    initTriageForm();
    scrollToDashboardAnchor();
    window.addEventListener('hashchange', scrollToDashboardAnchor);
  });
})();
