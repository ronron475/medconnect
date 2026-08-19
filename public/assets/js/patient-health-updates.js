/**
 * Poll for consultation completion, doctor final triage, and emergency referral.
 * Keys emergency modals by consultation_id so another visit is never shown.
 */
(function (global) {
  'use strict';

  const APP_BASE = global.APP_BASE || global.MC_ASSET_BASE || '';
  const POLL_MS = 10000;
  let sinceTs = Math.floor(Date.now() / 1000) - 30;
  let pollTimer = null;
  const seenCompleted = new Set();
  const seenEmergencyConsults = new Set();

  function doctorEmergencyStorageKey(consultationId) {
    return 'medconnect_er_final_shown_' + String(consultationId || '');
  }

  function alreadyShownDoctorEmergency(consultationId) {
    const key = String(consultationId || '');
    if (!key) return true;
    if (seenEmergencyConsults.has(key)) return true;
    try {
      return sessionStorage.getItem(doctorEmergencyStorageKey(key)) === '1';
    } catch (_) {
      return false;
    }
  }

  function markDoctorEmergencyShown(consultationId) {
    const key = String(consultationId || '');
    if (!key) return;
    seenEmergencyConsults.add(key);
    try {
      sessionStorage.setItem(doctorEmergencyStorageKey(key), '1');
    } catch (_) {}
  }

  function applyOnPageTriage(item) {
    if (!item || !item.consultation_id) return;
    const cid = String(item.consultation_id);
    const roots = document.querySelectorAll('[data-consult-id="' + cid + '"]');
    roots.forEach((root) => {
      const ai = root.querySelector('.js-consult-ai');
      const finalEl = root.querySelector('.js-consult-final');
      const byEl = root.querySelector('.js-consult-finalized');
      if (ai && item.ai_label) ai.textContent = item.ai_label;
      if (finalEl && item.final_label) finalEl.textContent = item.final_label;
      if (byEl) byEl.textContent = item.finalized_by || 'Doctor';
    });
  }

  function showDoctorEmergencyOverride(item) {
    const consultationId = item && (item.consultation_id || item.consult_id);
    if (!consultationId || alreadyShownDoctorEmergency(consultationId)) return;
    if (String(item.final_bucket || '') !== 'emergency') return;
    markDoctorEmergencyShown(consultationId);

    const message = item.message
      || 'Your doctor has classified your condition as an EMERGENCY. Please seek immediate in-person medical attention.';
    const facility = item.facility && typeof item.facility === 'object' ? item.facility : {};

    if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showDoctorEmergencyReferral === 'function') {
      window.mcPatientUrgencyModal.showDoctorEmergencyReferral({
        title: 'EMERGENCY — IMMEDIATE MEDICAL ATTENTION REQUIRED',
        message: message,
        facility: facility,
      });
    } else if (window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showEmergency === 'function') {
      window.mcPatientUrgencyModal.showEmergency(message, {
        title: 'EMERGENCY — IMMEDIATE MEDICAL ATTENTION REQUIRED',
        doctorReferral: true,
        facility: facility,
      });
    }

    document.dispatchEvent(new CustomEvent('medconnect:doctor-emergency-override', { detail: item }));

    if (typeof global.McNotifications !== 'undefined' && typeof global.McNotifications.poll === 'function') {
      try { global.McNotifications.poll(); } catch (_) {}
    }
  }

  function ensureBannerHost() {
    let host = document.getElementById('pmh-record-update-banner');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'pmh-record-update-banner';
    host.className = 'pmh-record-banner-host';
    host.setAttribute('aria-live', 'polite');
    const page = document.querySelector('.patient-page, .pdash-page, main');
    if (page && page.parentNode) {
      page.parentNode.insertBefore(host, page);
    } else {
      document.body.insertBefore(host, document.body.firstChild);
    }
    return host;
  }

  function showCompletionBanner(item) {
    if (!item || !item.id || seenCompleted.has(String(item.id))) return;
    seenCompleted.add(String(item.id));

    const host = ensureBannerHost();
    const banner = document.createElement('div');
    banner.className = 'pmh-record-banner';
    banner.innerHTML =
      '<div class="pmh-record-banner__body">' +
      '<strong>Consultation completed</strong>' +
      '<p>Your doctor has finalized your health file. Open My Health → Health Files to view it.</p>' +
      '</div>' +
      '<a class="pmh-btn pmh-btn--primary pmh-record-banner__cta" href="' +
      (item.detail_url || (APP_BASE + '/views/patient/my_health.php?tab=files#health-file-' + item.id)) +
      '">View Health Files</a>';

    host.appendChild(banner);

    const bucket = String(item.final_case_bucket || '').toLowerCase();
    if (bucket === 'emergency') {
      showDoctorEmergencyOverride({
        consultation_id: item.id,
        final_bucket: 'emergency',
        final_label: item.final_case_level || 'EMERGENCY',
        ai_label: item.ai_case_level || '',
        facility: item.facility || {},
        message: 'Your doctor has classified your condition as an EMERGENCY. Please seek immediate in-person medical attention.',
      });
    } else if (bucket === 'urgent' && window.mcPatientUrgencyModal && typeof window.mcPatientUrgencyModal.showUrgent === 'function') {
      window.mcPatientUrgencyModal.showUrgent(
        'Your doctor has finalized this consultation as URGENT. Follow your care plan and seek care promptly if symptoms worsen.',
        item.detail_url || ''
      );
    }

    document.dispatchEvent(new CustomEvent('medconnect:consultation-completed', { detail: item }));

    if (typeof global.McNotifications !== 'undefined' && typeof global.McNotifications.poll === 'function') {
      try { global.McNotifications.poll(); } catch (_) {}
    }
  }

  async function pollRecordUpdates() {
    if (!APP_BASE || document.hidden) return;
    try {
      const res = await fetch(
        APP_BASE + '/app/api/consultations/patient_record_updates.php?since=' + sinceTs + '&_=' + Date.now(),
        { credentials: 'same-origin', cache: 'no-store', headers: { 'X-MC-No-Loader': '1' } }
      );
      const json = await res.json();
      if (!json || !json.success) return;

      if (json.server_ts) {
        sinceTs = Math.max(sinceTs, json.server_ts - 5);
      }

      (json.completed || []).forEach(showCompletionBanner);

      const triageUpdates = json.triage_updates || [];
      triageUpdates.forEach((item) => {
        applyOnPageTriage(item);
        if (item && item.emergency) {
          showDoctorEmergencyOverride(item);
        }
      });

      const overrides = json.emergency_overrides || [];
      for (let i = 0; i < overrides.length; i++) {
        showDoctorEmergencyOverride(overrides[i]);
      }

      const active = json.active || [];
      active.forEach((item) => {
        const el = document.querySelector('[data-consult-status="' + item.id + '"]');
        if (el && item.status) {
          el.textContent = item.status.replace(/_/g, ' ');
          el.dataset.status = item.status;
        }
        applyOnPageTriage({
          consultation_id: item.id,
          ai_label: item.ai_case_level,
          final_label: item.final_case_level,
          finalized_by: item.finalized_by,
        });
      });
    } catch (_) {
      /* non-fatal */
    }
  }

  function startPolling() {
    if (!APP_BASE) return;
    pollRecordUpdates();
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollRecordUpdates, POLL_MS);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) pollRecordUpdates();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startPolling);
  } else {
    startPolling();
  }

  global.McPatientRecordUpdates = {
    poll: pollRecordUpdates,
    resetSince: function () {
      sinceTs = Math.floor(Date.now() / 1000) - 30;
    },
  };
})(window);
