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

  function doctorFinalStorageKey(consultationId, bucket) {
    return 'medconnect_final_shown_' + String(consultationId || '') + '_' + String(bucket || '');
  }

  function alreadyShownDoctorFinal(consultationId, bucket) {
    const key = String(consultationId || '');
    const level = String(bucket || '');
    if (!key || !level) return true;
    const storageKey = doctorFinalStorageKey(key, level);
    if (seenEmergencyConsults.has(storageKey) || seenEmergencyConsults.has(key)) return true;
    try {
      if (sessionStorage.getItem(storageKey) === '1') return true;
      if (level === 'emergency' && sessionStorage.getItem('medconnect_er_final_shown_' + key) === '1') return true;
    } catch (_) {}
    return false;
  }

  function markDoctorFinalShown(consultationId, bucket) {
    const key = String(consultationId || '');
    const level = String(bucket || '');
    if (!key || !level) return;
    const storageKey = doctorFinalStorageKey(key, level);
    seenEmergencyConsults.add(storageKey);
    try {
      sessionStorage.setItem(storageKey, '1');
      if (level === 'emergency') {
        sessionStorage.setItem('medconnect_er_final_shown_' + key, '1');
      }
    } catch (_) {}
  }

  function doctorEmergencyStorageKey(consultationId) {
    return doctorFinalStorageKey(consultationId, 'emergency');
  }

  function alreadyShownDoctorEmergency(consultationId) {
    return alreadyShownDoctorFinal(consultationId, 'emergency');
  }

  function markDoctorEmergencyShown(consultationId) {
    markDoctorFinalShown(consultationId, 'emergency');
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

  function showDoctorFinalOverride(item) {
    const consultationId = item && (item.consultation_id || item.consult_id);
    if (!consultationId) return;
    const bucket = String(item.final_bucket || '').toLowerCase().replace(/-/g, '_');
    if (bucket === 'emergency') {
      showDoctorEmergencyOverride(item);
      return;
    }
    if (bucket !== 'urgent' && bucket !== 'non_urgent') return;
    if (alreadyShownDoctorFinal(consultationId, bucket)) return;
    markDoctorFinalShown(consultationId, bucket);

    const label = String(item.final_label || (bucket === 'urgent' ? 'URGENT' : 'NON-URGENT')).toUpperCase();
    const message = item.message
      || ('Your doctor has classified your condition as ' + label + '.');
    const modal = window.mcPatientUrgencyModal;
    if (modal && typeof modal.showTriageResult === 'function') {
      modal.showTriageResult(bucket, message);
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

    const bucket = String(item.final_case_bucket || '').toLowerCase().replace(/-/g, '_');
    if (bucket === 'emergency') {
      showDoctorEmergencyOverride({
        consultation_id: item.id,
        final_bucket: 'emergency',
        final_label: item.final_case_level || 'EMERGENCY',
        ai_label: item.ai_case_level || '',
        facility: item.facility || {},
        message: 'Your doctor has classified your condition as an EMERGENCY. Please seek immediate in-person medical attention.',
      });
    } else if (bucket === 'urgent' || bucket === 'non_urgent') {
      showDoctorFinalOverride({
        consultation_id: item.id,
        final_bucket: bucket,
        final_label: item.final_case_level || (bucket === 'urgent' ? 'URGENT' : 'NON-URGENT'),
        message: bucket === 'urgent'
          ? 'Your doctor has finalized this consultation as URGENT. Follow your care plan and seek care promptly if symptoms worsen.'
          : 'Your doctor has finalized this consultation as NON-URGENT.',
      });
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
        showDoctorFinalOverride(item);
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
