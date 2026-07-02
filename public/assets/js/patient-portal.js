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

  window.filterSessions = function filterSessions(type) {
    const tbody = document.getElementById('sessions-tbody');
    const list = window.consultations || [];
    if (!tbody) return;

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const todayMs = today.getTime();

    const filtered = list.filter((c) => {
      const isUpcoming =
        parseConsultDate(c.consult_date) >= todayMs && c.status !== 'cancelled';
      return type === 'upcoming' ? isUpcoming : !isUpcoming;
    });

    tbody.innerHTML = '';

    if (filtered.length === 0) {
      tbody.innerHTML =
        '<tr><td colspan="5"><div class="mc-table-empty"><p>No ' +
        type +
        ' sessions found.</p></div></td></tr>';
      return;
    }

    filtered.forEach((c) => {
      let actionBtn = '';
      if (type === 'upcoming') {
        if (c.room_token) {
          actionBtn =
            '<a href="' +
            APP_BASE +
            '/views/consultation/video_room.php?token=' +
            encodeURIComponent(c.room_token) +
            '" class="mc-btn mc-btn--primary" style="padding:8px 14px;font-size:12px;text-decoration:none;display:inline-flex;">Join Video Call</a>';
        } else {
          actionBtn =
            '<button type="button" class="mc-btn mc-btn--outline" disabled style="padding:8px 14px;font-size:12px;">Waiting for Provider</button>';
        }
      } else {
        actionBtn =
          '<button type="button" class="mc-btn mc-btn--outline" style="padding:8px 14px;font-size:12px;">Summary</button>';
      }

      const dateLabel = c.consult_date
        ? new Date(c.consult_date + 'T00:00:00').toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
          })
        : '—';

      tbody.innerHTML +=
        '<tr>' +
        '<td data-label="Doctor" style="font-weight:700;">' +
        (c.provider_name || '—') +
        '</td>' +
        '<td data-label="Type"><span class="text-xs text-muted">' +
        (c.consult_type || '—') +
        '</span></td>' +
        '<td data-label="Date"><div style="font-weight:700;">' +
        dateLabel +
        '</div><div class="text-xs text-muted">' +
        (c.consult_time || '') +
        '</div></td>' +
        '<td data-label="Status"><span class="mc-badge">' +
        (c.status || '') +
        '</span></td>' +
        '<td data-label="Action">' +
        actionBtn +
        '</td></tr>';
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

  function initTriageForm() {
    const form = document.getElementById('patientTriageForm');
    if (!form) return;

    initBookingPicker();

    const alertEl = document.getElementById('triageFormAlert');
    const submitBtn = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const complaint = (form.querySelector('#chief_complaint')?.value || '').trim();
      const symptoms = Array.from(form.querySelectorAll('input[name="symptoms[]"]:checked'));
      const slotId = document.getElementById('booking_slot_id')?.value || '';

      if (!complaint && symptoms.length === 0) {
        showTriageAlert(alertEl, 'error', 'Please describe your complaint or select at least one symptom.');
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

      if (window.MedConnectAssessment && typeof window.MedConnectAssessment.run === 'function') {
        const preview = window.MedConnectAssessment.getLast();
        if (!preview) {
          await window.MedConnectAssessment.run({ skipAnimation: true });
        }
      }

      const fd = new FormData(form);
      fd.set('slot_id', slotId);

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.dataset.originalText = submitBtn.textContent;
        submitBtn.textContent = 'Saving…';
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
          showTriageAlert(alertEl, 'error', 'Unexpected server response. Please try again.');
          return;
        }

        if (data.success) {
          const booked = data.booked !== false;
          if (data.assessment && window.MedConnectAssessment) {
            window.MedConnectAssessment.render('aiAssessmentPanel', data.assessment);
          }
          showTriageAlert(
            alertEl,
            booked ? 'success' : 'error',
            data.message || (booked ? 'Assessment saved. Refreshing…' : 'Assessment saved, but the slot could not be booked.')
          );
          if (booked) {
            setTimeout(() => window.location.reload(), 1400);
          }
        } else {
          showTriageAlert(alertEl, 'error', data.message || 'Could not save assessment.');
        }
      } catch {
        showTriageAlert(alertEl, 'error', 'Network error. Please try again.');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = submitBtn.dataset.originalText || 'Submit Assessment & Book Slot';
        }
      }
    });
  }

  function syncSidebarFromRoute() {
    const pathMatch = window.location.pathname.match(/\/views\/patient\/([^/?#]+)/);
    if (!pathMatch) return false;

    const pageFile = pathMatch[1];
    // Combined dashboard page uses hash routing for in-page views
    if (pageFile === 'dashboard.php' && document.querySelector('.view-container')) {
      return false;
    }

    const pageStem = pageFile.replace(/\.php$/i, '');
    document.querySelectorAll('.sb-item').forEach((item) => {
      item.classList.toggle('active', item.dataset.view === pageStem);
    });
    return true;
  }

  function initHashRouting() {
    const legacyViews = {
      profile: APP_BASE + '/views/patient/profile.php',
      consultations: APP_BASE + '/views/patient/consultations.php',
      triage: APP_BASE + '/views/patient/triage.php',
      records: APP_BASE + '/views/patient/records.php',
    };

    const run = () => {
      if (syncSidebarFromRoute()) {
        return;
      }
      const viewId = window.location.hash.replace('#view-', '') || 'dashboard';
      if (!document.getElementById('view-' + viewId) && legacyViews[viewId]) {
        window.location.replace(legacyViews[viewId]);
        return;
      }
      if (document.getElementById('view-' + viewId)) {
        window.switchView(viewId);
      }
    };
    window.addEventListener('hashchange', run);
    run();
  }

  document.addEventListener('DOMContentLoaded', () => {
    syncSidebarFromRoute();
    initHashRouting();
    initTriageForm();
  });
})();
