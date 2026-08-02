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
  const pendingMessage = document.getElementById('phsPendingMessage');
  const rejectedBanner = document.getElementById('phsRejectedBanner');
  const rejectedMessage = document.getElementById('phsRejectedMessage');
  const requestBtn = document.getElementById('phsRequestUpdateBtn');
  const modal = document.getElementById('phsRequestModal');

  let summaryCache = null;

  function showAlert(msg, type) {
    if (!alertEl) return;
    alertEl.textContent = msg;
    alertEl.className = 'phs-alert phs-alert--' + (type || 'info') + ' is-visible';
    alertEl.hidden = false;
  }

  function listToText(items) {
    if (!items || !items.length) return 'None recorded';
    return items.join(', ');
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
    summaryCache = s;
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
      if (rejectedBanner) rejectedBanner.hidden = true;
      if (pendingMessage) {
        var assignee = pending.assigned_provider_label || 'your healthcare provider';
        pendingMessage.textContent =
          'Your request was sent to ' + assignee +
          ' for review on ' + (pending.created_at_label || 'recently') +
          '. Your official Health Summary will not change until approved.';
      }
      if (requestBtn) {
        requestBtn.hidden = true;
        requestBtn.disabled = true;
      }
    } else {
      if (pendingBanner) pendingBanner.hidden = true;
      if (requestBtn) {
        requestBtn.hidden = false;
        requestBtn.disabled = false;
      }

      const rejected = s.last_rejected_request;
      if (rejected && rejectedBanner) {
        rejectedBanner.hidden = false;
        if (rejectedMessage) {
          var note = (rejected.provider_note || '').trim();
          rejectedMessage.textContent = note !== ''
            ? note
            : 'Your doctor did not approve the last request. You may submit a corrected request.';
        }
      } else if (rejectedBanner) {
        rejectedBanner.hidden = true;
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

  function fillCurrentReadonly() {
    const s = summaryCache || {};
    document.getElementById('phsCurrentBlood').textContent = s.blood_type || 'Not recorded';
    document.getElementById('phsCurrentAllergies').textContent = listToText(s.allergies);
    document.getElementById('phsCurrentConditions').textContent = listToText(s.conditions);
    document.getElementById('phsCurrentMeds').textContent = listToText(s.medications);
  }

  function openModal() {
    fillCurrentReadonly();
    if (modal) {
      modal.hidden = false;
      document.getElementById('phsProposedBlood')?.focus();
    }
  }

  function closeModal() {
    if (modal) modal.hidden = true;
    ['phsRequestNote', 'phsProposedAllergies', 'phsProposedConditions', 'phsProposedMeds'].forEach(function (id) {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    const blood = document.getElementById('phsProposedBlood');
    if (blood) blood.value = '';
  }

  async function submitRequest() {
    const note = (document.getElementById('phsRequestNote')?.value || '').trim();
    const blood = (document.getElementById('phsProposedBlood')?.value || '').trim();
    const allergies = (document.getElementById('phsProposedAllergies')?.value || '').trim();
    const conditions = (document.getElementById('phsProposedConditions')?.value || '').trim();
    const medications = (document.getElementById('phsProposedMeds')?.value || '').trim();
    const btn = document.getElementById('phsRequestSubmit');
    if (btn) btn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('note', note);
      fd.append('blood_type', blood);
      fd.append('allergies', allergies);
      fd.append('existing_conditions', conditions);
      fd.append('current_medications', medications);
      const res = await fetch((window.APP_BASE || '') + '/app/api/patient/request_medical_update.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Request failed');
      closeModal();
      showAlert(data.message, 'success');
      await loadSummary();
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
