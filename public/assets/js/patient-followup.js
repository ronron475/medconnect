/**
 * Patient post-consultation follow-up request workflow.
 */
(function () {
  'use strict';

  const APP_BASE = window.APP_BASE || '';

  function getCsrfToken() {
    const body = document.body;
    if (body && body.dataset && body.dataset.csrf) {
      return body.dataset.csrf;
    }
    const root = document.getElementById('medconnectThemeRoot');
    if (root && root.dataset && root.dataset.csrf) {
      return root.dataset.csrf;
    }
    return '';
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  let modalEl = null;

  function ensureModal() {
    if (modalEl) return modalEl;
    modalEl = document.createElement('div');
    modalEl.id = 'psess-followup-modal';
    modalEl.className = 'psess-followup-modal';
    modalEl.hidden = true;
    modalEl.innerHTML =
      '<div class="psess-followup-modal__backdrop" data-close-followup></div>' +
      '<div class="psess-followup-modal__panel" role="dialog" aria-labelledby="psess-followup-title" aria-modal="true">' +
      '<button type="button" class="psess-followup-modal__close" data-close-followup aria-label="Close">&times;</button>' +
      '<h2 id="psess-followup-title">Request Follow-up</h2>' +
      '<p class="psess-followup-modal__sub">Describe how your condition has changed since your last visit. Our clinical AI will assess urgency.</p>' +
      '<div id="psess-followup-context" class="psess-followup-context"></div>' +
      '<label class="psess-followup-label" for="psess-followup-complaint">Updated chief complaint</label>' +
      '<textarea id="psess-followup-complaint" class="psess-followup-textarea" rows="4" maxlength="1000" placeholder="Describe your current symptoms…"></textarea>' +
      '<div id="psess-followup-result" class="psess-followup-result" hidden></div>' +
      '<div id="psess-followup-booking" class="psess-followup-booking" hidden></div>' +
      '<div class="psess-followup-modal__actions">' +
      '<button type="button" class="psess-btn psess-btn--outline" data-close-followup>Cancel</button>' +
      '<button type="button" class="psess-btn psess-btn--primary" id="psess-followup-submit">Submit for Assessment</button>' +
      '</div></div>';
    document.body.appendChild(modalEl);

    modalEl.addEventListener('click', function (e) {
      if (e.target.matches('[data-close-followup]')) {
        closeModal();
      }
    });

    document.getElementById('psess-followup-submit').addEventListener('click', submitFollowup);

    return modalEl;
  }

  function closeModal() {
    if (!modalEl) return;
    modalEl.hidden = true;
    document.body.classList.remove('psess-followup-open');
  }

  let activeConsultId = 0;
  let activeProviderId = 0;
  let activeCaseId = 0;

  function openModal(consult) {
    ensureModal();
    activeConsultId = parseInt(consult.id || '0', 10);
    activeProviderId = parseInt(consult.provider_id || '0', 10);
    activeCaseId = 0;

    const ctx = document.getElementById('psess-followup-context');
    const prev = consult.previous_chief_complaint || consult.consult_type || 'Previous visit';
    const date = consult.consult_date
      ? new Date(consult.consult_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
      : '—';
    ctx.innerHTML =
      '<dl class="psess-followup-dl">' +
      '<div><dt>Doctor</dt><dd>' + escapeHtml(consult.provider_name || 'Healthcare Provider') + '</dd></div>' +
      '<div><dt>Visit date</dt><dd>' + escapeHtml(date) + '</dd></div>' +
      '<div><dt>Previous complaint</dt><dd>' + escapeHtml(prev) + '</dd></div>' +
      '</dl>';

    document.getElementById('psess-followup-complaint').value = '';
    document.getElementById('psess-followup-result').hidden = true;
    document.getElementById('psess-followup-booking').hidden = true;
    document.getElementById('psess-followup-submit').hidden = false;
    document.getElementById('psess-followup-submit').textContent = 'Submit for Assessment';

    modalEl.hidden = false;
    document.body.classList.add('psess-followup-open');
    document.getElementById('psess-followup-complaint').focus();
  }

  async function submitFollowup() {
    const complaint = document.getElementById('psess-followup-complaint').value.trim();
    if (!complaint) {
      window.alert('Please describe your updated chief complaint.');
      return;
    }
    const csrf = getCsrfToken();
    if (!csrf) {
      window.alert('Security token missing. Please refresh the page.');
      return;
    }

    const btn = document.getElementById('psess-followup-submit');
    btn.disabled = true;
    btn.textContent = 'Assessing…';

    const fd = new FormData();
    fd.set('consultation_id', String(activeConsultId));
    fd.set('chief_complaint', complaint);
    fd.set('csrf_token', csrf);

    try {
      const res = await fetch(APP_BASE + '/app/api/patient/request_followup.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
      });
      const data = await res.json();
      btn.disabled = false;

      if (!data.success) {
        btn.textContent = 'Submit for Assessment';
        window.alert(data.message || 'Unable to submit follow-up request.');
        return;
      }

      activeCaseId = parseInt(data.case_id || '0', 10);
      showResult(data);
    } catch (err) {
      btn.disabled = false;
      btn.textContent = 'Submit for Assessment';
      window.alert('Network error. Please try again.');
    }
  }

  function showResult(data) {
    const resultEl = document.getElementById('psess-followup-result');
    const bookingEl = document.getElementById('psess-followup-booking');
    const submitBtn = document.getElementById('psess-followup-submit');

    let cls = 'routine';
    let title = 'Assessment Complete';
    if (data.emergency) {
      cls = 'emergency';
      title = 'Emergency — Seek Care Immediately';
    } else if (data.urgent) {
      cls = 'urgent';
      title = 'Urgent Follow-up Created';
    }

    resultEl.className = 'psess-followup-result psess-followup-result--' + cls;
    resultEl.innerHTML =
      '<h3>' + escapeHtml(title) + '</h3>' +
      '<p><strong>Classification:</strong> ' + escapeHtml(data.triage_display || data.classification || '—') + '</p>' +
      '<p><strong>Confidence:</strong> ' + escapeHtml(String(Math.round(data.confidence || 0))) + '%</p>' +
      '<p>' + escapeHtml(data.message || '') + '</p>';
    resultEl.hidden = false;
    submitBtn.hidden = true;

    if (data.emergency) {
      bookingEl.hidden = true;
      return;
    }

    if (data.urgent) {
      bookingEl.innerHTML =
        '<p class="psess-followup-booking__note">Your doctor has been notified. ' +
        (data.can_start_immediately
          ? 'They may start a video consultation when ready.'
          : 'You will be contacted when they are available.') +
        '</p>';
      bookingEl.hidden = false;
      return;
    }

    if (data.can_book_followup && activeCaseId > 0) {
      bookingEl.innerHTML =
        '<p class="psess-followup-booking__note">Book a follow-up with ' + escapeHtml(data.provider_name || 'your doctor') + ':</p>' +
        '<div id="psess-followup-slots" class="psess-followup-slots">Loading available slots…</div>';
      bookingEl.hidden = false;
      loadFollowupSlots(activeProviderId || data.provider_id);
    }
  }

  async function loadFollowupSlots(providerId) {
    const wrap = document.getElementById('psess-followup-slots');
    if (!wrap || !providerId) {
      if (wrap) wrap.textContent = 'No provider available for booking.';
      return;
    }

    const now = new Date();
    const today =
      now.getFullYear() + '-' +
      String(now.getMonth() + 1).padStart(2, '0') + '-' +
      String(now.getDate()).padStart(2, '0');

    try {
      const res = await fetch(
        APP_BASE + '/app/api/appointments/get_available_slots.php?provider_id=' +
          encodeURIComponent(providerId) + '&date=' + encodeURIComponent(today),
        { credentials: 'same-origin', headers: { 'X-MC-No-Loader': '1' } }
      );
      const data = await res.json();
      const slots = (data.slots || data.data?.slots || []).filter(function (slot) {
        return slot.bookable !== false;
      });
      if (!slots.length) {
        wrap.innerHTML = '<p>No slots available today. Please check back later or contact your provider.</p>';
        return;
      }
      wrap.innerHTML = slots.map(function (slot) {
        const label = slot.label || slot.start_time || slot.time || 'Slot';
        const id = slot.id || slot.slot_id;
        return '<button type="button" class="psess-followup-slot-btn" data-slot-id="' + escapeHtml(String(id)) + '">' + escapeHtml(label) + '</button>';
      }).join('');
      wrap.querySelectorAll('.psess-followup-slot-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          bookFollowupSlot(parseInt(btn.getAttribute('data-slot-id') || '0', 10));
        });
      });
    } catch (err) {
      wrap.textContent = 'Unable to load slots.';
    }
  }

  async function bookFollowupSlot(slotId) {
    if (!activeCaseId || !slotId) return;
    const csrf = getCsrfToken();
    const fd = new FormData();
    fd.set('case_id', String(activeCaseId));
    fd.set('slot_id', String(slotId));
    fd.set('csrf_token', csrf);

    try {
      const res = await fetch(APP_BASE + '/app/api/patient/book_followup.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
      });
      const data = await res.json();
      if (!data.success) {
        window.alert(data.message || 'Unable to book appointment.');
        return;
      }
      window.alert('Follow-up appointment booked successfully.');
      closeModal();
      window.location.reload();
    } catch (err) {
      window.alert('Network error. Please try again.');
    }
  }

  window.PatientFollowup = {
    open: openModal,
    close: closeModal,
    isEligible: function (consultId) {
      const eligible = window.followupEligibleIds || [];
      return eligible.indexOf(parseInt(consultId, 10)) !== -1;
    },
  };

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-request-followup]');
    if (!btn) return;
    e.preventDefault();
    const consultId = parseInt(btn.getAttribute('data-request-followup') || '0', 10);
    const list = window.consultations || [];
    const consult = list.find(function (c) { return parseInt(c.id, 10) === consultId; });
    if (consult) {
      openModal(consult);
    }
  });
})();
