/**
 * Patient Health Summary page
 */
(function () {
  'use strict';

  const root = document.getElementById('patientHealthSummaryRoot');
  if (!root) return;

  const apiBase = root.dataset.api || '';
  const csrf = root.dataset.csrf || '';
  const skeleton = document.getElementById('phsSkeleton');
  const content = document.getElementById('phsContent');
  const alertEl = document.getElementById('phsAlert');
  const pendingBanner = document.getElementById('phsPendingBanner');
  const requestBtn = document.getElementById('phsRequestUpdateBtn');
  const modal = document.getElementById('phsRequestModal');

  function showAlert(msg, type) {
    if (!alertEl) return;
    alertEl.textContent = msg;
    alertEl.className = 'phs-alert phs-alert--' + (type || 'info') + ' is-visible';
    alertEl.hidden = false;
  }

  function renderChipList(el, emptyEl, items, medClass) {
    if (!el) return;
    el.innerHTML = '';
    if (!items || !items.length) {
      if (emptyEl) emptyEl.hidden = false;
      return;
    }
    if (emptyEl) emptyEl.hidden = true;
    items.forEach(function (item) {
      const li = document.createElement('li');
      li.className = 'phs-chip' + (medClass ? ' phs-chip--med' : '');
      li.textContent = item;
      el.appendChild(li);
    });
  }

  function renderSummary(data) {
    const s = data.summary || data;
    document.getElementById('phsBloodType').textContent = s.blood_type || 'Not recorded';
    renderChipList(document.getElementById('phsAllergies'), document.getElementById('phsAllergiesEmpty'), s.allergies);
    renderChipList(document.getElementById('phsConditions'), document.getElementById('phsConditionsEmpty'), s.conditions);
    renderChipList(document.getElementById('phsMedications'), document.getElementById('phsMedicationsEmpty'), s.medications, true);

    const meta = s.metadata || {};
    document.getElementById('phsLastUpdated').textContent = meta.last_updated_at_label || 'Not available';
    document.getElementById('phsLastProvider').textContent = meta.last_updated_by || '—';

    const pending = s.pending_request;
    if (pending && pendingBanner) {
      pendingBanner.hidden = false;
      if (requestBtn) requestBtn.disabled = true;
    } else {
      if (pendingBanner) pendingBanner.hidden = true;
      if (requestBtn) {
        requestBtn.hidden = false;
        requestBtn.disabled = false;
      }
    }
  }

  async function loadSummary() {
    try {
      const res = await fetch(apiBase + '/health_summary.php', { credentials: 'same-origin' });
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Failed to load');
      renderSummary(data);
      if (skeleton) {
        skeleton.hidden = true;
        skeleton.setAttribute('aria-hidden', 'true');
      }
      if (content) {
        content.hidden = false;
        content.removeAttribute('aria-hidden');
      }
    } catch (err) {
      if (skeleton) {
        skeleton.hidden = true;
        skeleton.setAttribute('aria-hidden', 'true');
      }
      if (content) content.hidden = false;
      showAlert(err.message || 'Could not load health summary.', 'error');
    }
  }

  function openModal() {
    if (modal) {
      modal.hidden = false;
      document.getElementById('phsRequestNote')?.focus();
    }
  }

  function closeModal() {
    if (modal) modal.hidden = true;
    const note = document.getElementById('phsRequestNote');
    if (note) note.value = '';
  }

  async function submitRequest() {
    const note = (document.getElementById('phsRequestNote')?.value || '').trim();
    const btn = document.getElementById('phsRequestSubmit');
    if (btn) btn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('note', note);
      const res = await fetch((window.APP_BASE || '') + '/app/api/patient/request_medical_update.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Request failed');
      closeModal();
      showAlert(data.message, 'success');
      if (pendingBanner) pendingBanner.hidden = false;
      if (requestBtn) requestBtn.disabled = true;
    } catch (err) {
      showAlert(err.message || 'Could not submit request.', 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  requestBtn?.addEventListener('click', openModal);
  document.getElementById('phsRequestSubmit')?.addEventListener('click', submitRequest);
  modal?.querySelectorAll('[data-phs-close-modal]').forEach(function (el) {
    el.addEventListener('click', closeModal);
  });

  loadSummary();
})();
